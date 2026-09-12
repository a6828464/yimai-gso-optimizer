<?php
/**
 * AI 图片生成器 - 调用 AI 生图 API 生成配图
 *
 * 支持 Provider: 豆包/即梦(Seedream)、智谱(CogView)、通义万相(Wan2.6)
 * 后续可扩展: DALL-E、Midjourney、Stable Diffusion 等
 *
 * 所有 API Key 由用户自备，通过 AES-256-CBC 加密存储于 WordPress option 中
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_AI_Image_Generator {

    private $provider;
    private $api_key;
    private $model;
    private $size;
    private $custom_prompt_tpl;

    /**
     * @param string|null $provider  生图服务商 slug
     * @param string|null $api_key   用户自备 API Key（可选，不传则从 settings 读取）
     */
    public function __construct( $provider = null, $api_key = null ) {
        $this->provider = $provider ?: YIMAI_GSO_Settings::get( 'ai_image_provider', 'doubao' );
        $this->api_key  = $api_key ?: $this->get_stored_api_key();
        $this->model    = YIMAI_GSO_Settings::get( 'ai_image_model', '' );
        $this->size     = YIMAI_GSO_Settings::get( 'ai_image_size', '2K' );
        // 自定义提示词模板（变量替换用）
        $this->custom_prompt_tpl = YIMAI_GSO_Settings::get( 'ai_image_custom_prompt', '' );
    }

    // ──────────────────────────────────────────────
    //  公共方法
    // ──────────────────────────────────────────────

    /**
     * 获取所有支持的 AI 图片 Provider 列表
     *
     * @return array  [ slug => [name, need_key, key_url, key_setting, model_options, sizes, model_default] ]
     */
    public static function get_providers() {
        $providers = array(
            'doubao'   => array(
                'name'         => '豆包/即梦 Seedream',
                'need_key'     => true,
                'key_url'      => 'https://console.volcengine.com/ark/region:ark+cn-beijing/apikey',
                'key_setting'   => 'ai_image_doubao_key',
                'key_help'      => '前往火山方舟控制台获取 API Key',
                'model_options' => array(
                    'doubao-seedream-5-0-260128' => 'Seedream 5.0 Lite（推荐，2K/3K，支持1:1~16:9）',
                    'doubao-seedream-4-5-251128' => 'Seedream 4.5（2K/4K）',
                    'doubao-seedream-4-0-250828' => 'Seedream 4.0（1K/2K/4K）',
                ),
                'sizes'         => array( '2K', '16:9', '4:3', '9:16' ),
                'model_default'  => 'doubao-seedream-5-0-260128',
                'api_endpoint'   => 'https://ark.cn-beijing.volces.com/api/v3',
                'api_format'     => 'openai_compatible', // 兼容 OpenAI SDK
            ),
            'zhipu'    => array(
                'name'         => '智谱 CogView-4',
                'need_key'     => true,
                'key_url'      => 'https://open.bigmodel.cn/usercenter/apikeys',
                'key_setting'   => 'ai_image_zhipu_key',
                'key_help'      => '前往智谱AI开放平台获取 API Key',
                'model_options' => array(
                    'cogview-4' => 'CogView-4（推荐，1024×1024，支持中文）',
                ),
                'sizes'         => array( '1:1' ),
                'model_default'  => 'cogview-4',
                'api_endpoint'   => 'https://open.bigmodel.cn/api/paas/v4',
                'api_format'     => 'zhipu_sdk',     // 需智谱自有 SDK
            ),
            'wanxiang' => array(
                'name'         => '通义万相 wan2.6',
                'need_key'     => true,
                'key_url'      => 'https://bailian.console.aliyun.com/#/api-key',
                'key_setting'   => 'ai_image_wanxiang_key',
                'key_help'      => '前往阿里云百炼控制台获取 API Key',
                'model_options' => array(
                    'wan2.6-t2i' => 'wan2.6 文生图（推荐，1280-1440px，支持多比例）',
                ),
                'sizes'         => array( '1:1', '16:9', '4:3', '3:4', '9:16' ),
                'model_default'  => 'wan2.6-t2i',
                'api_endpoint'   => 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text2image/image-synthesis',
                'api_format'     => 'dashscope',      // 阿里云 DashScope SDK
            ),
        );

        return apply_filters( 'yimai_gso_ai_image_providers', $providers );
    }

    /**
     * 获取当前 Provider 的配置信息
     */
    public function get_provider_config() {
        $providers = self::get_providers();
        return isset( $providers[ $this->provider ] ) ? $providers[ $this->provider ] : null;
    }

    /**
     * 核心方法：根据 prompt + 上下文生成图片
     *
     * @param string       $prompt   图片提示词
     * @param int          $count    生成数量
     * @param array        $context  文章上下文 {title, keyword, section, style}
     * @return array|WP_Error        [{url, thumb, photographer, alt, source}] 或 WP_Error
     */
    public function generate( $prompt, $count = 1, $context = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', '请先在插件设置中填写 AI 生图 API Key' );
        }

        // 组装最终 prompt（文章上下文 + 用户自定义模板）
        $final_prompt = $this->build_prompt( $prompt, $context );

        // 限制生成数量
        $gen_count = max( 1, min( $count, 10 ) );

        // 分发到具体 Provider
        $images = array();
        $log_prefix = '[YIMAI_GSO_AI_IMG] ';

        for ( $i = 0; $i < $gen_count; $i++ ) {
            // 为每张图追加唯一性标记，强制模型生成不同画面
            $varied_prompt = $this->add_variation_hint( $final_prompt, $i, $gen_count );

            $result = null;
            switch ( $this->provider ) {
                case 'doubao':
                    $result = $this->generate_doubao( $varied_prompt );
                    break;
                case 'zhipu':
                    $result = $this->generate_zhipu( $varied_prompt );
                    break;
                case 'wanxiang':
                    $result = $this->generate_wanxiang( $varied_prompt );
                    break;
                default:
                    return new WP_Error( 'unknown_provider', '未知的 AI 生图服务商: ' . $this->provider );
            }

            if ( is_wp_error( $result ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( $log_prefix . "生成失败(#{$i}): " . $result->get_error_message() );
                }
                continue; // 单张失败不影响其他
            }

            if ( ! empty( $result['url'] ) ) {
                $images[] = $result;
            }
        }

        if ( empty( $images ) ) {
            $provider_name = $this->get_provider_config()['name'] ?? $this->provider;
            return new WP_Error( 'generation_failed', "{$provider_name} 生图失败，请检查 API Key 或额度" );
        }

        return $images;
    }

    /**
     * 测试连接（生成一张简单测试图）
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        // 直接调用 Provider 方法，绕过 generate() 的兜底错误，暴露真实 API 响应
        $prompt = '测试图片生成，简洁清晰';
        $result = null;

        switch ( $this->provider ) {
            case 'doubao':
                $result = $this->generate_doubao( $prompt );
                break;
            case 'zhipu':
                $result = $this->generate_zhipu( $prompt );
                break;
            case 'wanxiang':
                $result = $this->generate_wanxiang( $prompt );
                break;
            default:
                return new WP_Error( 'unknown_provider', '未知服务商: ' . $this->provider );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['url'] ) ) {
            return new WP_Error( 'no_output', 'API 返回异常，未包含图片URL' );
        }
        return true;
    }

    // ──────────────────────────────────────────────
    //  Prompt 组装
    // ──────────────────────────────────────────────

    /**
     * 为每张图添加唯一性提示，强制生成不同画面
     */
    private function add_variation_hint( $prompt, $index, $total ) {
        if ( $total <= 1 ) {
            return $prompt;
        }
        $hints = array( '独一无二的画面，与其他图片完全不同', '与同组其他图片画面各异', '独特的视觉表现，区别于其他图片' );
        $hint = isset( $hints[ $index ] ) ? $hints[ $index ] : '全新独特的画面表现';
        return $prompt . '，' . $hint;
    }

    /**
     * 组装最终 prompt：AI 描述 + 用户自定义模板
     */
    private function build_prompt( $base_prompt, $context = array() ) {
        $parts = array( trim( $base_prompt ) );

        // 补充文章上下文信息到 prompt（如果 base_prompt 不含上下文描述）
        $section_title = isset( $context['section'] ) ? $context['section'] : '';
        if ( $section_title && strpos( $base_prompt, $section_title ) === false ) {
            // section 已经在 image_prompts 的 prompt 中体现了，不重复添加
        }

        // 应用用户自定义提示词模板（变量替换）
        $custom = $this->apply_prompt_template( $context );
        if ( $custom ) {
            $parts[] = $custom;
        }

        return implode( '，', array_filter( $parts ) );
    }

    /**
     * 自定义提示词模板变量替换
     *
     * 支持的变量:
     *   {title}    — 文章标题
     *   {keyword}  — 文章关键词
     *   {section}  — 当前章节标题
     *   {style}    — 风格描述
     */
    public function apply_prompt_template( $context = array() ) {
        $tpl = $this->custom_prompt_tpl;
        if ( empty( $tpl ) ) {
            // 默认模板（强调无文字、中文环境）
            $tpl = '高清晰度，专业摄影品质，大师级构图，自然光或柔光照明，光影层次丰富，画面干净无任何文字字母logo数字，色彩鲜明自然，{style}风格，中国本土化真实场景，人物自然不做作，细节精致锐利';
        }

        $style_label = isset( $context['style'] ) ? $context['style'] : '写实摄影';

        $replacements = array(
            '{title}'   => isset( $context['title'] ) ? $context['title'] : '',
            '{keyword}' => isset( $context['keyword'] ) ? $context['keyword'] : '',
            '{section}' => isset( $context['section'] ) ? $context['section'] : '',
            '{style}'   => $style_label,
        );

        $result = str_replace( array_keys( $replacements ), array_values( $replacements ), $tpl );
        $result = trim( $result );

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Provider 实现
    // ──────────────────────────────────────────────

    /**
     * 豆包/即梦 Seedream（火山方舟）
     * API 格式兼容 OpenAI SDK，直接 HTTP POST 即可
     */
    private function generate_doubao( $prompt ) {
        $provider_cfg = self::get_providers()['doubao'];
        $endpoint = $provider_cfg['api_endpoint'] . '/images/generations';

        if ( empty( $this->model ) ) {
            $this->model = $provider_cfg['model_default'];
        }

        // Seedream 仅支持 2K/3K/4K，不支持自定义宽高和比例
        // 非正方形比例通过 prompt 中追加描述来实现
        $ratio_hints = array(
            '16:9' => '，横版宽屏构图，16:9比例',
            '4:3'  => '，横版标准构图，4:3比例',
            '9:16' => '，竖版手机屏幕构图，9:16比例',
        );
        $api_size = '2K';
        if ( isset( $ratio_hints[ $this->size ] ) ) {
            $prompt .= $ratio_hints[ $this->size ];
        }

        // 按火山方舟官方 Seedream API 规范（最小请求体）
        $body = array(
            'model'         => $this->model,
            'prompt'        => $prompt,
            'size'          => $api_size,
            'output_format' => 'png',
            'watermark'     => false,
        );

        $log_prefix = '[YIMAI_GSO_AI_IMG:doubao] ';
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $log_prefix . '请求 model=' . $this->model . ' size=' . $api_size );
        }

        $response = wp_remote_post( $endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( $log_prefix . '网络错误: ' . $response->get_error_message() );
            }
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $resp_raw  = wp_remote_retrieve_body( $response );
        $resp_body = json_decode( $resp_raw, true );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $log_prefix . 'HTTP ' . $http_code . ' | body: ' . substr( $resp_raw, 0, 500 ) );
        }

        // HTTP 状态码异常
        if ( $http_code < 200 || $http_code >= 300 ) {
            $err_detail = is_array( $resp_body ) ? json_encode( $resp_body, JSON_UNESCAPED_UNICODE ) : substr( $resp_raw, 0, 300 );
            return new WP_Error( 'http_error', "豆包API HTTP {$http_code}: " . $err_detail );
        }

        // API 返回错误
        if ( ! empty( $resp_body['error'] ) ) {
            $err_msg = isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : json_encode( $resp_body['error'], JSON_UNESCAPED_UNICODE );
            return new WP_Error( 'api_error', '豆包API: ' . $err_msg );
        }

        $img_url = $resp_body['data'][0]['url'] ?? '';
        if ( empty( $img_url ) ) {
            // 返回完整的响应体，方便排查
            $detail = json_encode( $resp_body, JSON_UNESCAPED_UNICODE );
            $detail = $detail ?: substr( $resp_raw, 0, 300 );
            return new WP_Error( 'no_url', '豆包API 未返回图片URL，响应: ' . $detail );
        }

        return array(
            'url'          => $img_url,
            'thumb'        => $img_url,
            'photographer' => 'Seedream AI',
            'alt'          => $prompt,
            'source'       => '豆包/即梦',
        );
    }

    /**
     * 智谱 CogView-4
     * 使用智谱自有 API 格式
     */
    private function generate_zhipu( $prompt ) {
        $provider_cfg = self::get_providers()['zhipu'];
        $endpoint = $provider_cfg['api_endpoint'] . '/images/generations';

        if ( empty( $this->model ) ) {
            $this->model = $provider_cfg['model_default'];
        }

        $body = array(
            'model'  => $this->model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => '1024x1024',
        );

        $response = wp_remote_post( $endpoint, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $resp_body['error'] ) ) {
            $err_msg = isset( $resp_body['error']['message'] ) ? $resp_body['error']['message'] : json_encode( $resp_body['error'] );
            return new WP_Error( 'api_error', '智谱CogView API 错误: ' . $err_msg );
        }

        $img_url = $resp_body['data'][0]['url'] ?? '';
        if ( empty( $img_url ) ) {
            return new WP_Error( 'no_url', '智谱CogView 未返回图片 URL' );
        }

        return array(
            'url'          => $img_url,
            'thumb'        => $img_url,
            'photographer' => 'CogView AI',
            'alt'          => $prompt,
            'source'       => '智谱AI',
        );
    }

    /**
     * 通义万相 wan2.6
     * 使用阿里云百炼 DashScope API
     */
    private function generate_wanxiang( $prompt ) {
        $provider_cfg = self::get_providers()['wanxiang'];
        $endpoint = $provider_cfg['api_endpoint'];
        $task_endpoint = 'https://dashscope.aliyuncs.com/api/v1/tasks/';

        if ( empty( $this->model ) ) {
            $this->model = $provider_cfg['model_default'];
        }

        // DashScope 通义万相是异步任务模式：提交 → 轮询 → 获取结果
        $size_map = array(
            '1:1'  => '1024*1024',
            '16:9' => '1280*720',
            '4:3'  => '1280*960',
            '3:4'  => '960*1280',
            '9:16' => '720*1280',
        );
        $api_size = isset( $size_map[ $this->size ] ) ? $size_map[ $this->size ] : '1024*1024';

        $body = array(
            'model' => $this->model,
            'input' => array(
                'prompt' => $prompt,
            ),
            'parameters' => array(
                'size'  => $api_size,
                'n'     => 1,
            ),
        );

        // 提交任务
        $response = wp_remote_post( $endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'X-DashScope-Async' => 'enable',
            ),
            'body' => json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $resp_body['code'] ) && $resp_body['code'] !== 'OK' ) {
            $err_msg = $resp_body['message'] ?? json_encode( $resp_body );
            return new WP_Error( 'api_error', '通义万相 API 错误: ' . $err_msg );
        }

        $task_id = $resp_body['output']['task_id'] ?? '';
        if ( empty( $task_id ) ) {
            return new WP_Error( 'no_task', '通义万相 未返回任务 ID' );
        }

        // 轮询任务结果（最多等待 30 秒）
        $max_attempts = 10;
        $attempt = 0;
        while ( $attempt < $max_attempts ) {
            $attempt++;
            sleep( 3 );

            $task_response = wp_remote_get( $task_endpoint . $task_id, array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
            ) );

            if ( is_wp_error( $task_response ) ) {
                continue;
            }

            $task_body = json_decode( wp_remote_retrieve_body( $task_response ), true );
            $task_status = $task_body['output']['task_status'] ?? '';

            if ( $task_status === 'SUCCEEDED' ) {
                $img_url = $task_body['output']['results'][0]['url'] ?? '';
                if ( empty( $img_url ) ) {
                    return new WP_Error( 'no_url', '通义万相 任务完成但未返回图片 URL' );
                }
                return array(
                    'url'          => $img_url,
                    'thumb'        => $img_url,
                    'photographer' => '万相 AI',
                    'alt'          => $prompt,
                    'source'       => '通义万相',
                );
            }

            if ( $task_status === 'FAILED' ) {
                $err_msg = $task_body['output']['message'] ?? '任务失败';
                return new WP_Error( 'task_failed', '通义万相 任务失败: ' . $err_msg );
            }
        }

        return new WP_Error( 'timeout', '通义万相 任务超时（已等待30秒）' );
    }

    // ──────────────────────────────────────────────
    //  工具方法
    // ──────────────────────────────────────────────

    /**
     * 从 WordPress option 中读取 API Key（自动解密）
     * 复用 YIMAI_GSO_Settings::get() 的 ENCRYPTED_KEYS 加密/解密机制
     */
    private function get_stored_api_key() {
        $provider_cfg = $this->get_provider_config();
        if ( ! $provider_cfg ) {
            return '';
        }

        $setting_key = $provider_cfg['key_setting'] ?? '';
        if ( empty( $setting_key ) ) {
            return '';
        }

        return YIMAI_GSO_Settings::get( $setting_key, '' );
    }

    /**
     * 获取指定 Provider 的 API Key（静态方法，用于测试连接等场景）
     */
    public static function get_api_key_for_provider( $provider_slug ) {
        $providers = self::get_providers();
        if ( ! isset( $providers[ $provider_slug ] ) ) {
            return '';
        }

        $setting_key = $providers[ $provider_slug ]['key_setting'] ?? '';
        if ( empty( $setting_key ) ) {
            return '';
        }

        return YIMAI_GSO_Settings::get( $setting_key, '' );
    }
}
