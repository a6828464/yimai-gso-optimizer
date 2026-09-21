<?php
/**
 * 搜索引擎推送类 - 支持：百度、必应(Bing) 主动推送
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_SEO_Pusher {

    private static $instance = null;
    
    // 推送平台常量
    const PLATFORM_BAIDU = 'baidu';
    const PLATFORM_BING = 'bing';
    const PLATFORM_GOOGLE = 'google';

    // API地址
    const API_BAIDU = 'http://data.zz.baidu.com/urls';
    const API_BING = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrl';
    const API_GOOGLE_INDEXING = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    const API_GOOGLE_PING = 'https://www.google.com/ping';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 钩子将在 init() 中注册
    }
    
    /**
     * 初始化钩子
     */
    public function init() {
        // 文章状态变更时自动推送（统一用一个钩子，避免 publish_post + transition_post_status 双钩子重复）
        add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 20, 3 );
        
        // 异步推送队列处理（发布流程不阻塞）
        add_action( 'yimai_gso_process_seo_push_queue', array( $this, 'process_seo_queue' ) );
        
        // AJAX 钩子
        add_action( 'wp_ajax_yimai_gso_seo_push_batch', array( $this, 'ajax_push_batch' ) );
        add_action( 'wp_ajax_yimai_gso_seo_push_sitemap', array( $this, 'ajax_push_sitemap' ) );
        add_action( 'wp_ajax_yimai_gso_seo_push_get_logs', array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_yimai_gso_seo_push_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_yimai_gso_seo_get_stats', array( $this, 'ajax_get_stats' ) );
        add_action( 'wp_ajax_yimai_gso_seo_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_yimai_gso_seo_push_get_posts', array( $this, 'ajax_get_posts' ) );
        add_action( 'wp_ajax_yimai_gso_save_seo_push_settings', array( $this, 'ajax_save_settings' ) );
        
        // 注册小工具
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
    }

    // 防止同一请求内重复推送的标志
    private $pushed_in_request = array();
    
    /**
     * 文章状态变更时触发推送（统一入口，只用这一个钩子）
     */
    // 防止同一请求内重复入队
    private $queued_in_request = array();

    /**
     * 文章状态变更时触发推送（统一入口，只用这一个钩子）
     * 【异步化】不再同步 HTTP 推送，改为入队由 WP-Cron 异步处理
     */
    public function on_post_status_change( $new_status, $old_status, $post ) {
        // 预览请求跳过（防止内存溢出）
        if ( function_exists( 'yimai_gso_is_preview_request' ) && yimai_gso_is_preview_request() ) {
            return;
        }

        // 新状态不是 publish 则跳过
        if ( $new_status !== 'publish' ) {
            return;
        }

        // 排除回收站恢复（trash→publish 不需要重新推送，旧URL可能仍有效）
        // 注意：auto-draft 是 WordPress 创建新文章时的初始状态（包括自动发布/批量发布），
        // auto-draft→publish 正是"新文章发布"的标准过渡，必须允许推送。
        if ( 'trash' === $old_status ) {
            return;
        }

        // 仅处理插件支持的内容类型（默认：post + page）
        if ( ! YIMAI_GSO_Post_Type_Helper::is_supported( $post->post_type ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 不支持的内容类型: ' . $post->post_type . ', 文章 ID: ' . $post->ID );
            }
            return;
        }
        
        // 检查自动推送开关
        if ( ! YIMAI_GSO_Settings::get( 'seo_push_auto_enabled', 0 ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 自动推送未开启，文章 ID: ' . $post->ID );
            }
            return;
        }

        // 更新推送：仅当开启时才入队
        if ( $old_status === 'publish' && ! YIMAI_GSO_Settings::get( 'seo_push_on_update', 1 ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 更新推送未开启，文章 ID: ' . $post->ID );
            }
            return;
        }

        // 同一请求内同一文章只入队一次
        if ( isset( $this->queued_in_request[ $post->ID ] ) ) {
            return;
        }
        $this->queued_in_request[ $post->ID ] = true;
        
        // 入队异步推送（不再同步阻塞发布流程）
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[YIMAI GSO] 文章入队: ' . $post->ID );
        }
        $this->add_to_seo_queue( $post->ID );
    }
    
    /**
     * 推送单篇文章
     */
    public function push_post( $post_id, $type = 'auto' ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return false;
        }
        
        $url = get_permalink( $post_id );
        $results = $this->push_urls( array( $url ), $type, array( $post_id ) );
        
        // 单篇推送时也标记已推送（由 push_urls 内部处理）
        return $results;
    }
    
    /**
     * 批量推送URL
     */
    public function push_urls( $urls, $type = 'manual', $post_ids = array() ) {
        $results = array(
            'baidu'  => array( 'success' => 0, 'fail' => 0, 'remain' => 0, 'msg' => '', 'codes' => array() ),
            'bing'   => array( 'success' => 0, 'fail' => 0, 'msg' => '', 'codes' => array() ),
            'google' => array( 'success' => 0, 'fail' => 0, 'msg' => '', 'codes' => array() ),
        );
        
        // 过滤空URL
        $urls = array_filter( $urls );
        if ( empty( $urls ) ) {
            return $results;
        }
        
        // 百度推送
        if ( $this->is_platform_enabled( self::PLATFORM_BAIDU ) ) {
            $results['baidu'] = $this->push_to_baidu( $urls );
        }
        
        // 必应推送
        if ( $this->is_platform_enabled( self::PLATFORM_BING ) ) {
            $results['bing'] = $this->push_to_bing( $urls );
        }

        // Google推送
        if ( $this->is_platform_enabled( self::PLATFORM_GOOGLE ) ) {
            $results['google'] = $this->push_to_google( $urls );
        }

        // 记录日志（按批量记录，每个URL一条，success字段记录该URL是否推送成功）
        error_log( '[YIMAI GSO] 开始记录日志，URL数: ' . count( $urls ) );
        foreach ( $urls as $url ) {
            $log_results = array(
                'baidu' => array(
                    'success' => ( $results['baidu']['success'] > 0 ) ? 1 : 0,
                    'msg'     => $results['baidu']['msg'] ?? '',
                ),
                'bing' => array(
                    'success' => ( $results['bing']['success'] > 0 ) ? 1 : 0,
                    'msg'     => $results['bing']['msg'] ?? '',
                ),
                'google' => array(
                    'success' => ( $results['google']['success'] > 0 ) ? 1 : 0,
                    'msg'     => $results['google']['msg'] ?? '',
                ),
            );
            error_log( '[YIMAI GSO] 记录日志 URL: ' . $url );
            $this->log_push( $url, $log_results, $type );
        }
        
        // 标记已推送：只要任一平台推送成功就标记
        $any_success = ( ! empty( $results['baidu']['success'] ) || ! empty( $results['bing']['success'] ) || ! empty( $results['google']['success'] ) );
        if ( $any_success && ! empty( $post_ids ) ) {
            foreach ( $post_ids as $pid ) {
                update_post_meta( intval( $pid ), '_yimai_gso_seo_pushed', time() );
            }
        }
        
        return $results;
    }
    
    /**
     * 检查平台是否启用
     */
    private function is_platform_enabled( $platform ) {
        return (bool) YIMAI_GSO_Settings::get( 'seo_push_' . $platform . '_enabled', 0 );
    }
    
    /**
     * 推送到百度（严格按官方文档：普通收录API）
     * 官方文档：https://ziyuan.baidu.com/college/courseinfo?id=267&page=2#h2_article_title12
     * 接口地址：http://data.zz.baidu.com/urls?site={纯域名}&token={密钥}
     * 请求方式：POST Content-Type:text/plain，body为每行一个URL
     */
    private function push_to_baidu( $urls ) {
        $api_url = $this->get_baidu_api_url();
        
        if ( is_wp_error( $api_url ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'remain' => 0,
                'msg' => $api_url->get_error_message(),
                'codes' => array()
            );
        }
        
        // 使用 wp_safe_remote_post 防止 SSRF
        $response = wp_safe_remote_post( $api_url, array(
            'body' => implode( "\n", $urls ),
            'headers' => array(
                'Content-Type' => 'text/plain',
                'User-Agent'  => 'curl/7.12.1',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'remain' => 0,
                'msg' => '请求失败: ' . $response->get_error_message(),
                'codes' => array()
            );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! $data || isset( $data['error'] ) ) {
            $msg = isset( $data['message'] ) ? $data['message'] : '推送失败';
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'remain' => 0,
                'msg' => 'HTTP ' . $code . ': ' . $msg,
                'codes' => array()
            );
        }
        
        return array(
            'success' => isset( $data['success'] ) ? intval( $data['success'] ) : 0,
            'fail' => count( $urls ) - ( isset( $data['success'] ) ? intval( $data['success'] ) : 0 ),
            'remain' => isset( $data['remain'] ) ? intval( $data['remain'] ) : 0,
            'msg' => '推送成功，剩余可推送: ' . ( isset( $data['remain'] ) ? $data['remain'] : 0 ) . ' 条',
            'codes' => array()
        );
    }
    
    /**
     * 构建百度推送API URL（严格按官方文档格式）
     * 官方示例：http://data.zz.baidu.com/urls?site=www.example.com&token=edk7yc4rEZP9pDQD
     */
    private function get_baidu_api_url( $type = '' ) {
        $api_address = YIMAI_GSO_Settings::get( 'seo_push_baidu_api_address', '' );
        
        // 优先使用用户粘贴的完整接口地址
        if ( ! empty( $api_address ) ) {
            $api_address = trim( $api_address );
            // 去掉可能存在的 type 参数（普通收录不需要）
            $api_address = preg_replace( '/[&?]type=[^&]*/', '', $api_address );
            // SSRF防护：验证域名白名单
            $parsed = wp_parse_url( $api_address );
            $host   = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
            $allowed_hosts = array( 'data.zz.baidu.com', 'www.baidu.com' );
            if ( ! in_array( $host, $allowed_hosts, true ) ) {
                return new WP_Error( 'baidu_invalid_url', '百度推送接口地址不合法，仅允许 data.zz.baidu.com' );
            }
            return $api_address;
        }
        
        // 回退：仅用 token 自动拼接（site 用纯域名）
        $token = YIMAI_GSO_Settings::get( 'seo_push_baidu_token', '' );
        if ( empty( $token ) ) {
            return new WP_Error( 'baidu_no_config', '未配置百度推送，请填写接口调用地址' );
        }
        $site = parse_url( home_url(), PHP_URL_HOST );
        return self::API_BAIDU . '?site=' . $site . '&token=' . $token;
    }
    
    /**
     * 推送到必应（IndexNow API 为主，旧版 Submit API 备选）
     * IndexNow 官方文档：https://www.indexnow.org/documentation
     * IndexNow 端点：https://api.indexnow.org/IndexNow
     */
    private function push_to_bing( $urls ) {
        // 优先使用 IndexNow API（必应官方推荐，简单高效）
        $indexnow_key = YIMAI_GSO_Settings::get( 'seo_push_bing_indexnow_key', '' );
        if ( ! empty( $indexnow_key ) ) {
            return $this->push_to_indexnow( $urls, $indexnow_key );
        }
        
        // 备选：旧版 Submit API
        $api_key = YIMAI_GSO_Settings::get( 'seo_push_bing_api_key', '' );
        if ( ! empty( $api_key ) ) {
            return $this->push_to_bing_legacy( $urls, $api_key );
        }
        
        return array(
            'success' => 0,
            'fail' => count( $urls ),
            'msg' => '未配置必应推送，请填写 IndexNow Key 或 API Key',
            'codes' => array()
        );
    }
    
    /**
     * 通过 IndexNow API 推送（必应官方推荐方式）
     * 端点：POST https://api.indexnow.org/IndexNow
     * 支持批量提交，单次最多 10000 条 URL
     */
    private function push_to_indexnow( $urls, $key ) {
        $host = parse_url( home_url(), PHP_URL_HOST );
        $key_location = home_url( '/' . $key . '.txt' );
        
        // IndexNow 单次最多 10000 条
        $batch = array_slice( $urls, 0, 10000 );
        
        $body = json_encode( array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $key_location,
            'urlList'     => $batch,
        ) );
        
        $response = wp_remote_post( 'https://api.indexnow.org/IndexNow', array(
            'body'    => $body,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
            ),
            'timeout'   => 10,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'msg' => 'IndexNow请求失败: ' . $response->get_error_message(),
                'codes' => array()
            );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        // 200 = 成功，202 = 已接受
        if ( $code === 200 || $code === 202 ) {
            return array(
                'success' => count( $batch ),
                'fail' => count( $urls ) - count( $batch ),
                'msg' => 'IndexNow推送成功 (' . $code . ')，共 ' . count( $batch ) . ' 条',
                'codes' => array()
            );
        }
        
        // 错误处理
        $error_msgs = array(
            400 => '请求格式错误，请检查IndexNow Key格式(32位十六进制)',
            403 => 'Key验证失败，请确保网站根目录已上传 {key}.txt 文件',
            422 => 'URL不属于当前域名',
            429 => '请求过于频繁，请稍后再试',
        );
        $msg = isset( $error_msgs[ $code ] ) ? $error_msgs[ $code ] : 'HTTP ' . $code;
        $body = wp_remote_retrieve_body( $response );
        if ( ! empty( $body ) && strlen( $body ) < 200 ) {
            $msg .= ': ' . $body;
        }
        
        return array(
            'success' => 0,
            'fail' => count( $urls ),
            'msg' => $msg,
            'codes' => array()
        );
    }
    
    /**
     * 通过旧版 Bing Submit API 推送（备选方案）
     * 接口：POST https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey={KEY}
     * 单次最多 500 条 URL
     */
    private function push_to_bing_legacy( $urls, $api_key ) {
        $site_url = home_url( '/' );
        
        // 批量提交（每次最多500条）
        $batches = array_chunk( $urls, 500 );
        $success = 0;
        $fail = 0;
        $messages = array();
        
        foreach ( $batches as $batch ) {
            $api_url = 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch?apikey=' . urlencode( $api_key );
            
            $response = wp_remote_post( $api_url, array(
                'body' => wp_json_encode( array(
                    'siteUrl' => $site_url,
                    'urlList' => $batch,
                ) ),
                'headers' => array(
                    'Content-Type' => 'application/json; charset=utf-8',
                ),
                'timeout'   => 10,
            ) );
            
            if ( is_wp_error( $response ) ) {
                $fail += count( $batch );
                $messages[] = $response->get_error_message();
                continue;
            }
            
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 200 || $code === 201 || $code === 202 ) {
                $success += count( $batch );
            } else {
                $fail += count( $batch );
                $body = wp_remote_retrieve_body( $response );
                $messages[] = 'HTTP ' . $code . ': ' . substr( $body, 0, 100 );
            }
        }
        
        return array(
            'success' => $success,
            'fail' => $fail,
            'msg' => $success > 0 ? '推送成功 ' . $success . ' 条' : implode( '; ', $messages ),
            'codes' => array()
        );
    }

    /**
     * 推送到 Google（双通道：优先 Indexing API，备选 Sitemap Ping）
     */
    private function push_to_google( $urls ) {
        $mode = YIMAI_GSO_Settings::get( 'seo_push_google_mode', 'sitemap_ping' );

        if ( $mode === 'indexing_api' ) {
            return $this->push_to_google_indexing_api( $urls );
        }

        // 默认：Sitemap Ping
        return $this->push_to_google_sitemap_ping();
    }

    /**
     * Google Sitemap Ping
     *
     * T2/正确性说明：Google 已于 2023 年正式**停用** sitemap ping 接口
     * （https://www.google.com/ping 返回 404）。该方法原为默认推送模式，
     * 意味着默认配置下每次推送都会发起一个必然失败的请求，并把失败计入统计，
     * 让后台长期显示「Google 推送失败」，而实际原因只是该接口已不存在。
     *
     * 处理策略（保守、不改变可用功能）：
     *  - 不再发起注定失败的请求，直接返回「已跳过」结果并说明原因；
     *  - 若要把内容推送给 Google，请在设置中改用 Indexing API（需 GCP 配置），
     *    或依赖 sitemap.xml + Search Console 自动发现（Google 官方推荐方式）。
     */
    private function push_to_google_sitemap_ping() {
        return array(
            'success' => 0,
            'fail'    => 0,
            'skipped' => 1,
            'msg'     => 'Google 已于 2023 年停用 sitemap ping 接口，本条已跳过（建议改用 Indexing API 或依赖 sitemap.xml 自动发现）。',
            'codes'   => array(),
        );
    }

    /**
     * Google Indexing API（即时推送，需Service Account）
     * 文档：https://developers.google.com/search/apis/indexing-api/v3/quickstart
     */
    private function push_to_google_indexing_api( $urls ) {
        $service_json = YIMAI_GSO_Settings::get( 'seo_push_google_service_json', '' );
        if ( empty( $service_json ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'msg' => '未配置Google Service Account JSON密钥',
                'codes' => array()
            );
        }

        $credentials = json_decode( $service_json, true );
        if ( ! $credentials || empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'msg' => 'Service Account JSON格式无效',
                'codes' => array()
            );
        }

        $access_token = $this->get_google_access_token( $credentials );
        if ( is_wp_error( $access_token ) ) {
            return array(
                'success' => 0,
                'fail' => count( $urls ),
                'msg' => 'Google认证失败: ' . $access_token->get_error_message(),
                'codes' => array()
            );
        }

        $success = 0;
        $fail = 0;
        $messages = array();

        // Indexing API 每次只能推送1条URL
        foreach ( $urls as $url ) {
            $response = $this->google_proxy_request( self::API_GOOGLE_INDEXING, array(
                'body' => wp_json_encode( array(
                    'url'  => $url,
                    'type' => 'URL_UPDATED',
                ) ),
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ),
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                $fail++;
                $messages[] = $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 200 ) {
                $success++;
            } else {
                $fail++;
                $body = wp_remote_retrieve_body( $response );
                $err_data = json_decode( $body, true );
                $err_msg = isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : 'HTTP ' . $code;
                $messages[] = $err_msg;
            }
        }

        return array(
            'success' => $success,
            'fail' => $fail,
            'msg' => $success > 0 ? 'Indexing API推送成功 ' . $success . ' 条' : implode( '; ', array_slice( $messages, 0, 3 ) ),
            'codes' => array()
        );
    }

    /**
     * 获取 Google OAuth2 Access Token（Service Account JWT）
     */
    private function get_google_access_token( $credentials ) {
        $client_email = $credentials['client_email'];
        $private_key  = $credentials['private_key'];

        $now = time();
        $jwt_header = json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) );
        $jwt_claims = json_encode( array(
            'iss'   => $client_email,
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ) );

        $jwt_header_b64  = rtrim( strtr( base64_encode( $jwt_header ), '+/', '-_' ), '=' );
        $jwt_claims_b64  = rtrim( strtr( base64_encode( $jwt_claims ), '+/', '-_' ), '=' );
        $jwt_signature_input = $jwt_header_b64 . '.' . $jwt_claims_b64;

        $signature = '';
        if ( ! openssl_sign( $jwt_signature_input, $signature, $private_key, 'SHA256' ) ) {
            return new WP_Error( 'google_jwt_sign', 'JWT签名失败，请检查private_key格式' );
        }
        $jwt_signature_b64 = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
        $jwt = $jwt_signature_input . '.' . $jwt_signature_b64;

        $response = $this->google_proxy_request( 'https://oauth2.googleapis.com/token', array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'google_token', 'Token请求失败: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['access_token'] ) ) {
            return $body['access_token'];
        }

        $err = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : '未知错误' );
        return new WP_Error( 'google_token', '获取Token失败: ' . $err );
    }

    /**
     * Google API HTTP 代理请求（国内服务器绕过 GFW 访问 Google 服务）
     * 
     * 使用方式：在 wp-config.php 中定义 YIMAI_GSO_GOOGLE_PROXY 常量
     * define( 'YIMAI_GSO_GOOGLE_PROXY', 'http://127.0.0.1:10809' );
     * 
     * @param string $url   请求 URL
     * @param array  $args  请求参数 {method, body, headers, timeout}
     * @return array|WP_Error 模拟 wp_remote_post 返回格式（兼容 wp_remote_retrieve_body / wp_remote_retrieve_response_code）
     */
    private function google_proxy_request( $url, $args = array() ) {
        $proxy = defined( 'YIMAI_GSO_GOOGLE_PROXY' ) ? YIMAI_GSO_GOOGLE_PROXY : '';

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, isset( $args['timeout'] ) ? $args['timeout'] : 15 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

        // 代理设置
        if ( ! empty( $proxy ) ) {
            curl_setopt( $ch, CURLOPT_PROXY, $proxy );
            // 自动检测代理类型：socks5:// → CURLPROXY_SOCKS5，否则 HTTP
            if ( strpos( $proxy, 'socks5://' ) === 0 || strpos( $proxy, 'socks5h://' ) === 0 ) {
                curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME );
            }
        }

        $method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'POST';
        $headers = array();

        if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
            foreach ( $args['headers'] as $key => $value ) {
                $headers[] = "$key: $value";
            }
        }

        if ( $method === 'POST' && isset( $args['body'] ) ) {
            curl_setopt( $ch, CURLOPT_POST, true );
            if ( is_array( $args['body'] ) ) {
                curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $args['body'] ) );
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
            }
        }

        if ( ! empty( $headers ) ) {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        }

        $body      = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $error     = curl_error( $ch );
        curl_close( $ch );

        if ( false === $body || ! empty( $error ) ) {
            return new \WP_Error( 'google_proxy', 'Google代理请求失败: ' . ( $error ?: '未知错误' ) );
        }

        // 返回格式兼容 WordPress HTTP API
        return array(
            'body'     => $body,
            'response' => array( 'code' => $http_code ),
        );
    }

    // ==================== 异步推送队列 ====================

    /** @var string 推送队列 option 键名 */
    const SEO_QUEUE_OPTION = 'yimai_gso_seo_push_queue';
    /** @var string 异步处理 cron 钩子名 */
    const SEO_QUEUE_CRON   = 'yimai_gso_process_seo_push_queue';

    /**
     * 将文章加入推送队列（transition_post_status 钩子中调用，毫秒级返回）
     *
     * @param int $post_id 文章ID
     */
    private function add_to_seo_queue( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) {
            return;
        }

        $queue = get_option( self::SEO_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }

        // 队列容量保护（最大50条，与SEO元数据队列保持一致）
        if ( count( $queue ) >= 50 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 推送队列已满(50)，文章未入队: ' . $post_id );
            }
            return;
        }

        // 去重
        if ( in_array( $post_id, $queue, true ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 文章已在队列中: ' . $post_id );
            }
            return;
        }

        $queue[] = $post_id;
        update_option( self::SEO_QUEUE_OPTION, $queue, false );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[YIMAI GSO] 文章已加入队列: ' . $post_id . '，队列长度: ' . count( $queue ) );
        }

        // 安排异步处理（60秒后执行，避开发布高峰期）
        if ( ! wp_next_scheduled( self::SEO_QUEUE_CRON ) ) {
            wp_schedule_single_event( time() + 60, self::SEO_QUEUE_CRON );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[YIMAI GSO] 已安排 WP-Cron 事件处理队列' );
            }
        }

        // 主动触发 wp-cron.php 立即处理（防止低流量站点队列堆积）
        if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
            $last_trigger = get_transient( 'yimai_gso_last_cron_trigger' );
            if ( false === $last_trigger ) {
                spawn_cron();
                set_transient( 'yimai_gso_last_cron_trigger', time(), 1 );
            }
        }
    }

    /**
     * WP-Cron 回调：处理推送队列
     * 每次最多处理 5 篇，避免超时
     */
    public function process_seo_queue() {
        error_log( '[YIMAI GSO] 开始处理推送队列' );

        $queue = get_option( self::SEO_QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            delete_option( self::SEO_QUEUE_OPTION );
            error_log( '[YIMAI GSO] 队列为空，跳过处理' );
            return;
        }

        error_log( '[YIMAI GSO] 队列中有 ' . count( $queue ) . ' 篇文章待推送' );

        // 每次最多处理5篇
        $batch = array_splice( $queue, 0, 5 );

        foreach ( $batch as $post_id ) {
            $post_id = absint( $post_id );
            if ( $post_id <= 0 ) {
                continue;
            }
            error_log( '[YIMAI GSO] 正在推送文章 ID: ' . $post_id );
            $results = $this->push_post( $post_id, 'auto' );
            yimai_gso_debug_log( '文章 ID: ' . $post_id . ' 推送结果: ' . wp_json_encode( $results ) );
        }

        // 队列还有剩余 → 30秒后继续处理
        if ( ! empty( $queue ) ) {
            update_option( self::SEO_QUEUE_OPTION, $queue, false );
            if ( ! wp_next_scheduled( self::SEO_QUEUE_CRON ) ) {
                wp_schedule_single_event( time() + 30, self::SEO_QUEUE_CRON );
            }
        } else {
            delete_option( self::SEO_QUEUE_OPTION );
        }
    }

    /**
     * 推送Sitemap
     */
    public function push_sitemap( $platforms = array() ) {
        if ( empty( $platforms ) ) {
            $platforms = array( self::PLATFORM_BAIDU, self::PLATFORM_BING, self::PLATFORM_GOOGLE );
        }
        
        $sitemap_url = home_url( '/sitemap.xml' );
        $results = array();
        
        foreach ( $platforms as $platform ) {
            if ( ! $this->is_platform_enabled( $platform ) ) {
                continue;
            }
            
            switch ( $platform ) {
                case self::PLATFORM_BAIDU:
                    $api_url = $this->get_baidu_api_url();
                    if ( ! is_wp_error( $api_url ) ) {
                        $response = wp_remote_post( $api_url, array(
                            'body' => $sitemap_url,
                            'headers' => array( 'Content-Type' => 'text/plain', 'User-Agent' => 'curl/7.12.1' ),
                            'timeout' => 10,
                        ) );
                        $results['baidu'] = $this->parse_sitemap_response( $response );
                    }
                    break;
                    
                case self::PLATFORM_BING:
                    // 必应通过 IndexNow 或旧版 API 提交 Sitemap URL
                    $indexnow_key = YIMAI_GSO_Settings::get( 'seo_push_bing_indexnow_key', '' );
                    $api_key = YIMAI_GSO_Settings::get( 'seo_push_bing_api_key', '' );
                    
                    if ( ! empty( $indexnow_key ) ) {
                        $results['bing'] = $this->push_to_indexnow( array( $sitemap_url ), $indexnow_key );
                    } elseif ( ! empty( $api_key ) ) {
                        $results['bing'] = $this->push_to_bing_legacy( array( $sitemap_url ), $api_key );
                    } else {
                        $results['bing'] = array(
                            'success' => 0,
                            'msg' => '未配置必应推送Key'
                        );
                    }
                    break;

                case self::PLATFORM_GOOGLE:
                    $results['google'] = $this->push_to_google_sitemap_ping();
                    break;
            }
        }

        return $results;
    }
    
    /**
     * 解析Sitemap推送响应
     */
    private function parse_sitemap_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return array( 'success' => 0, 'msg' => $response->get_error_message() );
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( isset( $data['error'] ) ) {
            return array( 'success' => 0, 'msg' => isset( $data['message']) ? $data['message'] : '推送失败' );
        }
        
        return array( 'success' => 1, 'msg' => 'Sitemap推送成功' );
    }
    
    /**
     * 记录推送日志
     */
    private function log_push( $url, $results, $type ) {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_seo_push_logs';
        
        // 确保表存在
        $this->ensure_log_table();
        
        $log_data = array(
            'url' => $url,
            'type' => $type,
            'baidu_success' => $results['baidu']['success'] ?? 0,
            'baidu_msg' => $results['baidu']['msg'] ?? '',
            'bing_success' => $results['bing']['success'] ?? 0,
            'bing_msg' => $results['bing']['msg'] ?? '',
            'google_success' => $results['google']['success'] ?? 0,
            'google_msg' => $results['google']['msg'] ?? '',
            'created_at' => current_time( 'mysql' ),
        );
        
        yimai_gso_debug_log( '准备写入日志: ' . wp_json_encode( $log_data ) );
        
        $result = $wpdb->insert( $table, $log_data );
        
        if ( $result === false ) {
            error_log( '[YIMAI GSO] 日志写入失败: ' . $wpdb->last_error );
        } else {
            error_log( '[YIMAI GSO] 日志写入成功，ID: ' . $wpdb->insert_id );
        }
    }
    
    /**
     * 确保日志表存在
     */
    private function ensure_log_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_seo_push_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url text NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'manual',
            baidu_success int(11) DEFAULT 0,
            baidu_msg varchar(255) DEFAULT '',
            bing_success int(11) DEFAULT 0,
            bing_msg varchar(255) DEFAULT '',
            google_success int(11) DEFAULT 0,
            google_msg varchar(255) DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_url (url(255))
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        // dbDelta 不会删除列，但可能无法正确添加新列（取决于格式匹配）
        // 手动检查并添加 google_success 和 google_msg 字段（向后兼容）
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
        $column_names = wp_list_pluck( $columns, 'Field' );
        
        if ( ! in_array( 'google_success', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN google_success int(11) DEFAULT 0 AFTER bing_msg" );
        }
        if ( ! in_array( 'google_msg', $column_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN google_msg varchar(255) DEFAULT '' AFTER google_success" );
        }
    }
    
    /**
     * 获取推送系统诊断信息
     */
    /**
     * 获取推送统计
     */
    public function get_stats( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_seo_push_logs';
        $this->ensure_log_table();
        
        $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
        
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN baidu_success > 0 THEN 1 END) as baidu_success,
                COUNT(CASE WHEN bing_success > 0 THEN 1 END) as bing_success,
                COUNT(CASE WHEN google_success > 0 THEN 1 END) as google_success,
                COUNT(CASE WHEN baidu_success > 0 OR bing_success > 0 OR google_success > 0 THEN 1 END) as success_count
            FROM $table 
            WHERE created_at >= %s
        ", $since ) );
        
        return array(
            'total' => (int) $stats->total,
            'baidu_success' => (int) $stats->baidu_success,
            'bing_success' => (int) $stats->bing_success,
            'google_success' => (int) $stats->google_success,
            'success_rate' => $stats->total > 0 ? round( $stats->success_count / $stats->total * 100, 1 ) : 0,
        );
    }
    
    /**
     * 获取推送日志列表
     */
    public function get_logs( $page = 1, $per_page = 20, $platform = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_seo_push_logs';
        $this->ensure_log_table();
        
        $offset = ( $page - 1 ) * $per_page;
        $where = '1=1';
        $params = array();
        
        if ( ! empty( $platform ) ) {
            switch ( $platform ) {
                case 'baidu':
                    $where .= ' AND baidu_success > 0';
                    break;
                case 'bing':
                    $where .= ' AND bing_success > 0';
                    break;
                case 'google':
                    $where .= ' AND google_success > 0';
                    break;
            }
        }
        
        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );
        
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
        
        return array(
            'logs' => $logs,
            'total' => (int) $total,
            'pages' => ceil( $total / $per_page ),
            'current_page' => $page,
        );
    }
    
    /**
     * 清除日志
     */
    public function clear_logs( $before_date = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'yimai_gso_seo_push_logs';
        
        if ( empty( $before_date ) ) {
            return $wpdb->query( "TRUNCATE TABLE $table" );
        }
        
        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            $before_date
        ) );
    }

    // ==================== AJAX处理 ====================
    
    /**
     * AJAX: 推送单篇文章
     */
    public function ajax_push_single() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '无效的文章ID' ) );
        }
        
        $results = $this->push_post( $post_id, 'manual' );
        wp_send_json_success( array( 'results' => $results ) );
    }
    
    /**
     * AJAX: 批量推送
     */
    public function ajax_push_batch() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'intval', $_POST['post_ids'] ) : array();
        if ( empty( $post_ids ) ) {
            wp_send_json_error( array( 'message' => '请选择要推送的文章' ) );
        }
        
        $urls = array();
        // 批量预加载 permalink（update_post_cache 确保 get_permalink 不走数据库）
        if ( ! empty( $post_ids ) ) {
            update_meta_cache( 'post', $post_ids );
        }
        foreach ( $post_ids as $post_id ) {
            $url = get_permalink( $post_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }
        
        $results = $this->push_urls( $urls, 'batch', $post_ids );
        wp_send_json_success( array( 'results' => $results ) );
    }
    
    /**
     * AJAX: 推送Sitemap
     */
    public function ajax_push_sitemap() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $platforms = isset( $_POST['platforms'] ) ? array_map( 'sanitize_text_field', $_POST['platforms'] ) : array();
        $results = $this->push_sitemap( $platforms );
        wp_send_json_success( array( 'results' => $results ) );
    }
    
    /**
     * AJAX: 获取推送日志
     */
    public function ajax_get_logs() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $platform = isset( $_POST['platform'] ) ? sanitize_text_field( $_POST['platform'] ) : '';
        
        $data = $this->get_logs( $page, 20, $platform );
        wp_send_json_success( $data );
    }
    
    /**
     * AJAX: 清除日志
     */
    public function ajax_clear_logs() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $before_date = isset( $_POST['before_date'] ) ? sanitize_text_field( $_POST['before_date'] ) : '';
        $deleted = $this->clear_logs( $before_date );
        wp_send_json_success( array( 'deleted' => $deleted ) );
    }
    
    /**
     * AJAX: 获取统计
     */
    public function ajax_get_stats() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $stats = $this->get_stats();
        wp_send_json_success( $stats );
    }
    
    /**
     * AJAX: 获取诊断信息
     */
    
    /**
     * AJAX: 测试连接
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $platform = isset( $_POST['platform'] ) ? sanitize_text_field( $_POST['platform'] ) : '';
        $result = array();
        
        switch ( $platform ) {
            case 'baidu':
                $api_url = $this->get_baidu_api_url();
                if ( is_wp_error( $api_url ) ) {
                    $result = array( 'success' => false, 'message' => $api_url->get_error_message() );
                } else {
                    // 测试推送首页URL
                    $test_url = home_url( '/' );
                    $response = $this->push_to_baidu( array( $test_url ) );
                    $result = array(
                        'success' => $response['success'] > 0,
                        'message' => $response['msg']
                    );
                }
                break;
                
            case 'bing':
                $indexnow_key = YIMAI_GSO_Settings::get( 'seo_push_bing_indexnow_key', '' );
                $api_key = YIMAI_GSO_Settings::get( 'seo_push_bing_api_key', '' );

                if ( ! empty( $indexnow_key ) ) {
                    // 测试 IndexNow 连接（推送首页）
                    $test_url = home_url( '/' );
                    $response = $this->push_to_indexnow( array( $test_url ), $indexnow_key );
                    $result = array(
                        'success' => $response['success'] > 0,
                        'message' => $response['msg']
                    );
                } elseif ( ! empty( $api_key ) ) {
                    // 测试旧版 API 连接（推送首页）
                    $test_url = home_url( '/' );
                    $response = $this->push_to_bing_legacy( array( $test_url ), $api_key );
                    $result = array(
                        'success' => $response['success'] > 0,
                        'message' => $response['msg']
                    );
                } else {
                    $result = array( 'success' => false, 'message' => '未配置必应推送Key' );
                }
                break;

            case 'google':
                $mode = YIMAI_GSO_Settings::get( 'seo_push_google_mode', 'sitemap_ping' );
                if ( $mode === 'indexing_api' ) {
                    $service_json = YIMAI_GSO_Settings::get( 'seo_push_google_service_json', '' );
                    if ( empty( $service_json ) ) {
                        $result = array( 'success' => false, 'message' => '未配置Google Service Account JSON密钥' );
                    } else {
                        $test_url = home_url( '/' );
                        $response = $this->push_to_google_indexing_api( array( $test_url ) );
                        $result = array(
                            'success' => $response['success'] > 0,
                            'message' => $response['msg']
                        );
                    }
                } else {
                    $response = $this->push_to_google_sitemap_ping();
                    $result = array(
                        'success' => $response['success'] > 0,
                        'message' => $response['msg']
                    );
                }
                break;

            default:
                $result = array( 'success' => false, 'message' => '未知平台' );
        }
        
        wp_send_json_success( $result );
    }
    
    /**
     * AJAX: 获取文章列表（用于批量推送）
     */
    public function ajax_get_posts() {
        check_ajax_referer( 'yimai_gso_seo_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }
        $category = isset( $_POST['category'] ) ? intval( $_POST['category'] ) : 0;
        $per_page = isset( $_POST['per_page'] ) ? min( intval( $_POST['per_page'] ), 200 ) : 50;
        
        $args = array(
            'post_type'      => YIMAI_GSO_Post_Type_Helper::get_supported(),
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        );
        
        if ( $category > 0 ) {
            $args['cat'] = $category;
        }
        
        $query = new WP_Query( $args );
        $posts = array();
        
        // 批量预加载 post meta（用于 _yimai_gso_seo_pushed 字段）
        $push_post_ids = wp_list_pluck( $query->posts, 'ID' );
        if ( ! empty( $push_post_ids ) ) {
            update_meta_cache( 'post', $push_post_ids );
        }

        foreach ( $query->posts as $post ) {
            $cats = get_the_category( $post->ID );
            $cat_names = array();
            if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
                foreach ( $cats as $cat ) {
                    $cat_names[] = $cat->name;
                }
            }
            
            $posts[] = array(
                'ID'        => $post->ID,
                'post_title'=> $post->post_title,
                'post_date' => $post->post_date,
                'permalink' => get_permalink( $post->ID ),
                'category'  => implode( ', ', $cat_names ),
                'pushed'    => (bool) get_post_meta( $post->ID, '_yimai_gso_seo_pushed', true ),
            );
        }
        
        wp_send_json_success( array(
            'posts' => $posts,
            'total' => $query->found_posts,
        ) );
    }
    
    /**
     * 注册仪表盘小工具
     */
    public function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'yimai_gso_seo_push_widget',
            '一麦AI - 搜索引擎推送统计',
            array( $this, 'render_dashboard_widget' )
        );
    }
    
    /**
     * 渲染仪表盘小工具
     */
    public function render_dashboard_widget() {
        $stats = $this->get_stats( 7 );
        ?>
        <div class="yimai-gso-seo-widget">
            <div class="yimai-gso-seo-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon baidu"><span class="dashicons dashicons-search"></span></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo esc_html( $stats['baidu_success'] ); ?></div>
                        <div class="stat-label">百度推送</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon bing"><span class="dashicons dashicons-migrate"></span></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo esc_html( $stats['bing_success'] ); ?></div>
                        <div class="stat-label">必应推送</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon google"><span class="dashicons dashicons-google"></span></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo esc_html( $stats['google_success'] ); ?></div>
                        <div class="stat-label">Google推送</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon total"><span class="dashicons dashicons-chart-line"></span></div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo esc_html( $stats['success_rate'] ); ?>%</div>
                        <div class="stat-label">成功率</div>
                    </div>
                </div>
            </div>
            <p class="yimai-gso-widget-footer">
                <a href="<?php echo admin_url( 'admin.php?page=yimai-gso-center&tab=seo-push' ); ?>" class="button button-primary">
                    管理推送 <span class="dashicons dashicons-arrow-right-alt" style="margin-top:2px"></span>
                </a>
            </p>
        </div>
        <style>
        .yimai-gso-seo-widget { padding: 0 0 10px; }
        .yimai-gso-seo-stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 12px; }
        .stat-card { display: flex; align-items: center; gap: 10px; padding: 12px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 8px; }
        .stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; }
.stat-icon.baidu { background: linear-gradient(135deg, #4caf50, #2e7d32); }
.stat-icon.bing { background: linear-gradient(135deg, #0078d4, #005a9e); }
.stat-icon.google { background: linear-gradient(135deg, #ea4335, #34a853); }
.stat-icon.total { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
        .stat-info { flex: 1; }
        .stat-value { font-size: 20px; font-weight: 700; color: #1a1a2e; line-height: 1.2; }
        .stat-label { font-size: 12px; color: #6c757d; margin-top: 2px; }
        .yimai-gso-widget-footer { text-align: right; margin: 0; }
        </style>
        <?php
    }

    /**
     * AJAX: 保存 SEO 推送配置（替代内联 POST handler）
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 解析 serialized 表单数据
        $raw = isset( $_POST['data'] ) ? $_POST['data'] : '';
        parse_str( $raw, $fields );

        $seo_push_available = true;
        if ( $seo_push_available ) {
            YIMAI_GSO_Settings::set( 'seo_push_baidu_enabled', isset( $fields['yimai_gso_seo_push_baidu_enabled'] ) ? 1 : 0 );
            YIMAI_GSO_Settings::set( 'seo_push_baidu_api_address', esc_url_raw( $fields['yimai_gso_seo_push_baidu_api_address'] ?? '' ) );
            YIMAI_GSO_Settings::set( 'seo_push_baidu_token', sanitize_text_field( $fields['yimai_gso_seo_push_baidu_token'] ?? '' ) );
            YIMAI_GSO_Settings::set( 'seo_push_bing_enabled', isset( $fields['yimai_gso_seo_push_bing_enabled'] ) ? 1 : 0 );
            YIMAI_GSO_Settings::set( 'seo_push_bing_indexnow_key', sanitize_text_field( $fields['yimai_gso_seo_push_bing_indexnow_key'] ?? '' ) );
            YIMAI_GSO_Settings::set( 'seo_push_bing_api_key', sanitize_text_field( $fields['yimai_gso_seo_push_bing_api_key'] ?? '' ) );
            YIMAI_GSO_Settings::set( 'seo_push_google_enabled', isset( $fields['yimai_gso_seo_push_google_enabled'] ) ? 1 : 0 );
            YIMAI_GSO_Settings::set( 'seo_push_google_mode', sanitize_text_field( $fields['yimai_gso_seo_push_google_mode'] ?? 'sitemap_ping' ) );
            $google_json = isset( $fields['yimai_gso_seo_push_google_service_json'] ) ? sanitize_textarea_field( wp_unslash( $fields['yimai_gso_seo_push_google_service_json'] ) ) : '';
            if ( ! YIMAI_GSO_Settings::is_masked_value( $google_json ) ) {
                YIMAI_GSO_Settings::set( 'seo_push_google_service_json', $google_json );
            }
            YIMAI_GSO_Settings::set( 'seo_push_auto_enabled', isset( $fields['yimai_gso_seo_push_auto_enabled'] ) ? 1 : 0 );
            YIMAI_GSO_Settings::set( 'seo_push_on_update', isset( $fields['yimai_gso_seo_push_on_update'] ) ? 1 : 0 );
            YIMAI_GSO_Settings::set( 'seo_push_baidu_verify_code', sanitize_text_field( wp_unslash( $fields['yimai_gso_seo_push_baidu_verify_code'] ?? '' ) ) );
            $tongji_allowed = array( 'script' => array( 'src' => array(), 'type' => array(), 'async' => array(), 'defer' => array() ) );
            YIMAI_GSO_Settings::set( 'seo_push_baidu_tongji_code', wp_kses( wp_unslash( $fields['yimai_gso_seo_push_baidu_tongji_code'] ?? '' ), $tongji_allowed ) );
        }
        YIMAI_GSO_Settings::set( 'yimai_gso_robots_content', sanitize_textarea_field( wp_unslash( $fields['yimai_gso_robots_content'] ?? '' ) ) );

        wp_send_json_success( array( 'message' => '已保存' ) );
    }
}
