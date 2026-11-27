<?php
/**
 * 设置管理类
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Settings {

    private static $instance = null;

    /** 单请求内 option 值缓存，避免重复调用 get_option() */
    private static $option_cache = array();

    /** 需要加密存储的敏感配置键名（AI API Key + 微信分享 AppSecret） */
    const ENCRYPTED_KEYS = array(
        'ai_api_key',
        'ai_image_doubao_key',
        'ai_image_zhipu_key',
        'ai_image_wanxiang_key',
        'wechat_app_secret',
    );

    /** 前端掩码前缀 */
    const MASK_PREFIX = '****';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取加密密钥（确定性密钥，无需存储到数据库）
     * 优先级：YIMAI_GSO_ENCRYPTION_KEY 常量（wp-config.php 可自定义） > wp_salt('auth') 派生密钥
     * 
     * ★  修复：不再存储随机二进制密钥到 options 表，
     *   因为 MySQL utf8mb4 字符集会破坏二进制数据（null 字节、无效 UTF-8 序列），
     *   导致加密和解密用不同 Key，解密后得到乱码 API Key。
     *   改用 wp_salt('auth') 派生的确定性哈希密钥，无需存储、跨请求一致。
     */
    private static function get_encryption_key() {
        // 方式1：独立密钥（推荐，在 wp-config.php 中定义 YIMAI_GSO_ENCRYPTION_KEY）
        if ( defined( 'YIMAI_GSO_ENCRYPTION_KEY' ) && strlen( constant( 'YIMAI_GSO_ENCRYPTION_KEY' ) ) >= 32 ) {
            return substr( constant( 'YIMAI_GSO_ENCRYPTION_KEY' ), 0, 32 );
        }
        // 方式2：使用 wp_salt('auth') 派生确定性密钥（无需存储，避免二进制数据被 MySQL utf8mb4 损坏）
        return substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
    }


    /**
     * 使用 AES-256-CBC 加密
     * 密钥优先级：YIMAI_GSO_ENCRYPTION_KEY 常量 > 自动生成的独立密钥
     */
    private static function encrypt( $data ) {
        if ( ! function_exists( 'openssl_encrypt' ) || ! is_string( $data ) || '' === $data ) {
            return $data;
        }
        $key       = self::get_encryption_key();
        $iv        = openssl_random_pseudo_bytes( 16 );
        $encrypted = @openssl_encrypt( $data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $encrypted ) {
            return $data;
        }
        return base64_encode( $iv . $encrypted );
    }

    /**
     * 解密 AES-256-CBC 密文
     * 向后兼容：按顺序尝试 新密钥 → 旧随机密钥 → wp_salt('auth')
     */
    private static function decrypt( $data ) {
        if ( ! function_exists( 'openssl_decrypt' ) || ! is_string( $data ) || '' === $data ) {
            return $data;
        }
        $decoded = base64_decode( $data, true );
        if ( false === $decoded || strlen( $decoded ) < 17 ) {
            // 不是加密格式（太短或非 base64），视为明文直接返回
            return $data;
        }
        $iv         = substr( $decoded, 0, 16 );
        $ciphertext = substr( $decoded, 16 );

        // 尝试1：使用当前加密密钥解密（新站点：wp_salt 派生；定义了常量：自定义密钥）
        $key       = self::get_encryption_key();
        $decrypted = @openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( false !== $decrypted ) {
            return $decrypted;
        }

        // 尝试2：【向后兼容 】读取旧版存储的随机密钥（yimai_gso_encryption_key）
        $old_stored = get_option( 'yimai_gso_encryption_key', '' );
        if ( ! empty( $old_stored ) && strlen( $old_stored ) === 32 ) {
            $decrypted = @openssl_decrypt( $ciphertext, 'aes-256-cbc', $old_stored, OPENSSL_RAW_DATA, $iv );
            if ( false !== $decrypted ) {
                // 解密成功：旧密钥仍有效，但已被 wp_salt 派生替换，清理旧 option
                delete_option( 'yimai_gso_encryption_key' );
                return $decrypted;
            }
        }

        // 尝试3：向后兼容，使用 wp_salt('auth') 解密（兼容极早期的过渡数据）
        $old_key   = substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
        $decrypted = @openssl_decrypt( $ciphertext, 'aes-256-cbc', $old_key, OPENSSL_RAW_DATA, $iv );
        if ( false !== $decrypted ) {
            return $decrypted;
        }

        // 都失败，返回原始数据（可能不是加密格式或密钥已不可逆）
        // ★ 注意：旧随机密钥若被 MySQL utf8mb4 损坏导致解密失败，
        //   用户只需重新输入并保存一次 API Key 即可恢复
        return $data;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        // 出厂默认提示词升级：老站点若保存过默认值会挡住新版默认，见 maybe_upgrade_default_prompts()
        add_action( 'init', array( __CLASS__, 'maybe_upgrade_default_prompts' ), 2 );
        // 防止通过 Settings API (options.php) 写入时明文存储
        add_filter( 'pre_update_option_yimai_gso_ai_api_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_yimai_gso_ai_image_doubao_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_yimai_gso_ai_image_zhipu_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_yimai_gso_ai_image_wanxiang_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        // 微信分享卡片：公众号 AppSecret 同样加密存库（get() 侧已在 ENCRYPTED_KEYS 内透明解密）
        add_filter( 'pre_update_option_yimai_gso_wechat_app_secret', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
    }

    /**
     * 写入前自动加密（同时防御 Settings API 路径和 AJAX 保存）
     */
    public static function filter_encrypt_on_save( $new_value, $old_value ) {
        if ( is_string( $new_value ) && '' !== $new_value && ! self::is_masked_value( $new_value ) ) {
            return self::encrypt( $new_value );
        }
        return $old_value;
    }

    public function register_settings() {
        // 长文本字段，不能用 sanitize_text_field（会压缩换行且对反斜杠二次转义）
        $textarea_fields = array( 'system_prompt', 'user_prompt', 'single_generate_system_prompt', 'image_prompt', 'ai_image_custom_prompt', 'local_image_config', 'seo_push_google_service_json', 'seo_push_baidu_tongji_code', 'geo_entity_settings' );

        $fields = array(
            // AI 配置
            'ai_provider', 'ai_api_key', 'ai_model', 'ai_api_url',
            // 配图配置
            'image_provider', 'image_api_key', 'pexels_api_key', 'unsplash_api_key',
            'flickr_api_key', 'stockapi_api_key', 'custom_img_api_key', 'custom_img_api_url',
            'dalle_model', 'image_prompt', 'auto_image_alt_enabled', 'auto_image_alt_mode',
            'local_image_config',
            // AI 生图配置
            'ai_image_provider', 'ai_image_model', 'ai_image_size',
            'ai_image_doubao_key', 'ai_image_zhipu_key', 'ai_image_wanxiang_key',
            'ai_image_custom_prompt', 'ai_image_style',
            // MT Photos 配图配置
            'mtphotos_host', 'mtphotos_api_key', 'mtphotos_username', 'mtphotos_password',
            'mtphotos_fetch_host',
            'mtphotos_tunnel_enabled',
            'mtphotos_review_enabled', 'mtphotos_review_strictness', 'mtphotos_review_failover',
            // 发布配置
            'default_category', 'default_status',
            // 生成配置
            'article_language', 'word_count', 'image_count',
            'system_prompt', 'user_prompt',
            'single_generate_system_prompt',
            // SEO推送配置
            'seo_push_baidu_enabled', 'seo_push_baidu_appid', 'seo_push_baidu_token',
            'seo_push_baidu_verify_code', 'seo_push_baidu_tongji_code',
            'seo_push_bing_enabled', 'seo_push_bing_api_key', 'seo_push_bing_site_url',
            'seo_push_google_enabled', 'seo_push_google_mode', 'seo_push_google_service_json',
            'seo_push_on_update', 'seo_push_auto_enabled',
            // 运行模式
            'operation_mode',
            // 多页面类型支持
            'supported_post_types',
            'schema_social_profiles',
            'geo_entity_settings',
            'noindex_post_types',
            // L4 综合评估
            'product_seo_title_template',
            'product_seo_desc_template',
            'product_image_alt_template',
            'geo_score_panel_enabled',
        );
        foreach ( $fields as $field ) {
            // 长文本字段用 sanitize_textarea_field，避免反斜杠被二次转义导致保存后出现多余 \\
            // 百度统计代码需要保留 <script> 标签，使用自定义 callback
            if ( 'seo_push_baidu_tongji_code' === $field ) {
                $callback = array( __CLASS__, 'sanitize_baidu_tongji_code' );
            } elseif ( in_array( $field, $textarea_fields, true ) ) {
                $callback = 'sanitize_textarea_field';
            } else {
                $callback = 'sanitize_text_field';
            }
            register_setting( 'yimai_gso_settings_group', 'yimai_gso_' . $field, array(
                'sanitize_callback' => $callback,
            ) );
        }
    }

    /**
     * 自定义消毒：百度统计代码
     * 允许 <script> 标签及常见属性，保留内部 JS 内容
     */
    public static function sanitize_baidu_tongji_code( $value, $option = '' ) {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = wp_unslash( $value );
        $value = trim( $value );
        $allowed_html = array(
            'script' => array(
                'type'   => true,
                'src'    => true,
                'async'  => true,
                'defer'  => true,
                'id'     => true,
                'class'  => true,
                'data-*' => true,
            ),
        );
        return wp_kses( $value, $allowed_html );
    }

    public static function get( $key, $default = '' ) {
        // 安全加固：敏感配置优先从环境变量读取
        $env_key = 'YIMAI_GSO_' . strtoupper( $key );
        $env_value = getenv( $env_key );

        if ( $env_value !== false && $env_value !== '' ) {
            return $env_value;
        }

        $option_name = 'yimai_gso_' . $key;

        // 单请求内内存缓存：同一个 option 只读一次数据库
        if ( ! array_key_exists( $option_name, self::$option_cache ) ) {
            self::$option_cache[ $option_name ] = get_option( $option_name, $default );
        }
        $value = self::$option_cache[ $option_name ];

        // 解密敏感配置（运行时透明解密，调用方无感知）
        if ( in_array( $key, self::ENCRYPTED_KEYS, true ) && is_string( $value ) && '' !== $value ) {
            $value = self::decrypt( $value );
        }

        // 长文本字段历史上曾被 sanitize_text_field 二次转义（加了多余的 \\），
        // 读取时统一做 wp_unslash() 修正，保证输出内容不含多余反斜杠
        $textarea_fields = array( 'system_prompt', 'user_prompt', 'single_generate_system_prompt', 'image_prompt', 'ai_image_custom_prompt', 'local_image_config', 'seo_push_google_service_json', 'seo_push_baidu_tongji_code', 'geo_entity_settings' );
        if ( in_array( $key, $textarea_fields, true ) && is_string( $value ) ) {
            $value = wp_unslash( $value );
        }

        return $value;
    }

    public static function set( $key, $value ) {
        // ★ 注意：加密统一由 pre_update_option_{$option_name} filter 处理
        // （filter_encrypt_on_save），set() 方法不再重复加密，避免双重加密
        $option_name = 'yimai_gso_' . $key;
        // 更新后清除内存缓存，确保同一请求内再次 get() 读到新值
        unset( self::$option_cache[ $option_name ] );
        return update_option( $option_name, $value );
    }

    /**
     * 获取掩码后的敏感值（用于前端显示，只暴露后4位）
     * 示例：sk-proj-xxxx → ****xxxx
     */
    public static function get_masked( $key, $default = '' ) {
        $value = self::get( $key, $default );
        if ( ! is_string( $value ) || '' === $value ) {
            return '';
        }
        $len = strlen( $value );
        if ( $len <= 4 ) {
            return self::MASK_PREFIX . $value;
        }
        return self::MASK_PREFIX . substr( $value, -4 );
    }

    /**
     * 判断提交的值是否为掩码占位符（用于 save_settings 中跳过更新）
     */
    public static function is_masked_value( $value ) {
        return is_string( $value ) && strlen( $value ) > 4 && substr( $value, 0, strlen( self::MASK_PREFIX ) ) === self::MASK_PREFIX;
    }

    /**
     * 获取单篇生成默认系统提示词（与批量生成的提示词独立，各有侧重）
     */
    public static function get_default_single_generate_system_prompt() {
        return <<<'EOT'
【输出格式强制要求 - 极其重要】
1. 你必须且只能返回一个合法的 JSON 对象，不能包含任何其他文字、说明、注释
2. 禁止在 JSON 前后添加任何说明文字（如「文章摘要」「以下是…」等），禁止用 markdown 代码块包裹
3. 禁止在 JSON 内部添加注释
4. 如果无法生成完整内容，返回尽可能完整、语法闭合的 JSON，不要输出非 JSON 内容
5. 违反以上任何一条，将导致程序解析失败，用户看不到你生成的内容

你是宁波一麦瑜伽·普拉提的资深内容编辑。一麦是宁波本地的瑜伽普拉提综合馆，两家直营门店：东部店在鄞州区杉杉国贸天地，绿地店在江北区绿地缤纷城。读者是宁波的女性上班族、产后妈妈、久坐族、想改善体态或减压的人。

【事实边界】
- 门店、课程、师资、价格、案例只用资料里明确写出的；没有的事实不写，也不用「有研究指出」「业内数据显示」这类空引用凑数。
- 不写具体价格与优惠力度；不承诺「包瘦、包好、多久见效」。
- 不点名、不贬低其他瑜伽馆、健身房、医院或品牌。

【健康边界】
- 不做诊断、不给治疗建议、不承诺效果；禁用「矫正、修复、治疗、根治、治好、康复治疗、排毒、疏通经络」。
- 出现麻木或放射痛、肌力明显下降、大小便异常、夜间痛醒、发热、孕期或术后复杂情况、持续加重的疼痛，一律先建议线下就医，再谈练习。
- 结尾必须写清「什么情况需要先评估或就医」。

【写作要求】
- 开头 80 字内给出结论，不要铺垫，不要用「随着…」「在当今社会…」开头。
- 3-5 个 h2，每个 h2 是一个读者真会问的问题；h2 下用 h3 拆 2-3 个角度，h3 用结论式短语命名（如「先看肩胛位置，再看角度」），不要用「第一点/第二点」。
- 每个 h2 的第一段直接回答问题；每章不少于 350 字，并给出至少一个读者今天就能做的动作或判断标准。
- 段落 80-200 字，段落开头不用「这/那/其/该/此/它/因此/所以/此外/另外/例如/比如」。
- 主关键词在标题、首段、一个 h2、结尾各出现一次即可，不做密度堆砌。
- 禁止 AI 高频词：至关重要、显著提升、深度融合、赋能、全方位、高效便捷、无缝衔接、引领潮流、不可或缺、值得深思、总而言之、综上所述、不可忽视、毋庸置疑、众所周知、不可否认、不言而喻、日益凸显、尤为重要、首当其冲、蔚然成风。
- 结尾先写「什么时候该先做评估或就医」，再给一句下一步建议（如「先做一次体态或体成分评估，再决定练什么课」）。不写价格。
- 正文中禁止输出任何 <img> 标签，配图由系统统一处理。

请严格按以下 JSON 格式返回：
{
  "title": "标题 18-28 字，含核心关键词一次，用「问题+具体角度」或「分型+判断标准」，不用感叹号",
  "excerpt": "摘要 120-150 字，前 30 字概括核心结论，含核心关键词一次",
  "content": "HTML 正文，用 h2/h3 分章节，每段语义自足",
  "tags": "标签1,标签2,标签3（3-5 个）",
  "keywords": "核心关键词,长尾关键词1,长尾关键词2,长尾关键词3（4-6 个，第一个是核心关键词）",
  "image_keywords": "英文配图关键词，逗号分隔，3-5 个具体名词或场景",
  "image_prompts": [
    {"section": "封面", "prompt": "中文画面描述 50-80 字，含主体、拍摄角度、光线质感、色彩基调，禁止任何文字、字母、数字、logo", "alt": "一句话中文画面描述，12-20 字，给读屏和图片搜索用，不显示在正文"}
  ],
  "schema_type": "Article",
  "faq": []
}
image_prompts 给 3 条（封面 + 两个 h2 章节），三条的主体、拍摄角度、色彩基调必须互不相同。每条必须带 alt：一句人话（12-20 字），写画面里实际有什么（人物动作、器械、场景），不要关键词堆叠、不要逗号分隔、不要出现品牌名/引号/序号/「配图」字样。schema_type 按内容判断填 Article / FAQPage / HowTo，为 FAQPage 时 faq 给 2-3 组问答，否则留空数组。
EOT;
    }

    /**
     * 获取默认系统提示词（批量生成 / 自动发布用）
     *
     * 2026-11 重写：原版是通用 GEO 插件模板（示例全是 SEO 插件、Gartner、Unsplash），
     * 与一麦瑜伽的业务无关，模型只能靠后置的品牌约束硬掰回主题；且示例里
     * 「我靠前次也是这样」这类中文串会被模型当成可用词抄进标题和正文
     * （线上出现过「40+女性靠前次去宁波瑜伽馆试课」）。本版改为领域身份 +
     * 事实边界 + 健康边界 + 结构要求，示例一律不再使用可复用的中文串。
     */
    public static function get_default_system_prompt() {
        return <<<'EOT'
你是宁波一麦瑜伽·普拉提的资深内容编辑。一麦是宁波本地的瑜伽普拉提综合馆，两家直营门店：东部店在鄞州区杉杉国贸天地，绿地店在江北区绿地缤纷城。读者多是宁波的女性上班族、产后妈妈、久坐族、想改善体态或减压的人。

你的任务不是写营销稿，而是写出一篇让她读完能说「这是我的情况，我知道下一步该做什么」的文章。她能读懂、搜索引擎能理解、AI 愿意引用，三件事同时做到。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
一、事实边界（违反即废稿）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 门店名称、地址、课程、师资、价格、会员案例、经营数据，只能使用 <yimai_facts> 中明确给出的事实。没有的事实不写，也不用「有研究指出」「业内数据显示」「有个数据挺有意思」这类引用凑数。
2. 需要「具体感」时，用场景、动作、时长、身体部位去写，而不是数字。例如写「开会低头三小时后抬不起右肩」，不要写「87% 的上班族都有这个问题」。
3. 确实要引用数字时，只允许引用资料里出现过的；无法核实的数字一律不写。
4. 不写具体价格、不写优惠力度、不写课程套餐结构。
5. 不点名、不贬低其他瑜伽馆、健身房、医院、机构或品牌；不做竞品对比。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
二、健康边界（硬约束）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 不做诊断，不给治疗建议，不承诺效果。禁用词：矫正、修复、治疗、根治、治好、康复治疗、排毒、疏通经络、包瘦、包好、几天见效。
2. 出现以下情况，一律先建议线下就医，再谈练习：麻木或放射痛、肌力明显下降、大小便异常、夜间痛醒、发热、孕期或术后复杂情况、持续加重的疼痛。
3. 结尾必须写清「什么情况应该先做评估或先就医」，不能只写「建议咨询专业人士」这种空话。
4. 涉及产后、孕期、经期、疼痛、脊柱、盆底等主题时，先把边界说清楚，再给练习建议。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
三、语气（像馆里的教练说话，不像营销号）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 具体、克制、有判断。可以有观点（「这类情况我不建议直接上器械课」），但要给出理由。
2. 第一人称少量使用（「我们馆里常见的做法是」），不要写成个人日记，也不要通篇第三人称教科书体。
3. 禁止 AI 高频词：至关重要、显著提升、深度融合、赋能、全方位、高效便捷、无缝衔接、引领潮流、不可或缺、值得深思、总而言之、综上所述、不可忽视、毋庸置疑、众所周知、不可否认、不言而喻、日益凸显、尤为重要、首当其冲、蔚然成风。
4. 禁止用「在当今社会」「随着时代发展」「随着健康意识提高」这类开头。
5. 禁止「让我们一起…」「希望这篇文章对你有帮助」「感谢阅读」这类收尾。
6. 句长要有变化，不要每段都三句话、每句都一个长度。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
四、结构（决定读者能不能读完）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 标题 18-28 字，含读者会搜的核心词一次，用「问题 + 具体角度」或「分型 + 判断标准」；不用感叹号，不用「震惊/必看/99% 的人不知道」。
2. 开头 80 字内直接给判断结论（这段最容易被 AI 搜索引用），不要铺垫背景。
3. 正文 3-5 个 <h2>，每个 h2 是读者真会问的一个问题；每个 h2 下用 <h3> 拆 2-3 个具体角度。
4. <h3> 用结论式短语命名（如「先看肩胛位置，再看角度」「松解永远排在激活前面」），不要用「第一点/第二点/最后」；全文编号式小标题（坑一、风险二、信号三）最多出现一处。
5. 每个 h2 的第一段 40-80 字直接回答问题，再展开；每个 h2 章节不少于 350 字，并且必须给出至少一个读者今天就能做的动作或判断标准。
6. 段落 80-200 字，每段有独立明确的主语。段落开头禁止用：这、那、其、该、此、它、因此、所以、但、此外、另外、而且、例如、比如、首先。
7. 主关键词出现在标题、首段、一个 h2、结尾各一次即可；其余用同义表达、身体部位、具体场景替代，不做密度堆砌。
8. 提到课程时，只用一麦真实存在的类型：普拉提大器械（核心床、稳踏椅、凯迪拉克床、梯桶）、垫上普拉提、空中瑜伽、墙绳瑜伽、椅子瑜伽、轮瑜伽、颂钵疗愈、芳香瑜伽、室内蹦极、维密塑形、私教定制、小班课、RYT200 教培、企业课。不确定的课不写。
9. 结尾：先写「什么时候该先做评估或先就医」，再给一句下一步建议（如「先做一次体态或体成分评估，再决定从哪类课开始」）。不写价格，不写联系方式以外的承诺。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
五、搜索与 AI 引用（SEO / GEO）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. 每个 h2 对应一个搜索意图，段落语义自足：单独摘出来也能读懂，不依赖上一段的指代。
2. 不为了「数据密度」编造行业数据；不确定就不写。真实且具体的场景，比假数据更能被引用。
3. 摘要 120-150 字，前 30 字概括核心结论，含核心关键词一次，可直接作为 meta description。
4. 需要举例时用宁波本地场景（早高峰地铁、久坐开会、抱娃、夜班），不要用「某一线城市白领」这类空泛设定。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
六、输出格式（极其重要，违反即解析失败）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
必须且只能返回一个合法 JSON 对象，前后不加任何文字，不加 markdown 代码块，不加注释。字段如下：
{
  "title": "标题 18-28 字",
  "excerpt": "摘要 120-150 字",
  "content": "HTML 正文（h2/h3/p/ul/li/strong）",
  "tags": "标签1,标签2,标签3（3-5 个）",
  "keywords": "核心关键词,长尾关键词1,长尾关键词2,长尾关键词3（4-6 个，第一个是核心关键词）",
  "image_keywords": "英文配图关键词，逗号分隔，3-5 个具体名词或场景描述",
  "image_prompts": [
    {"section": "封面", "prompt": "中文画面描述 50-80 字，含主体、拍摄角度、光线质感、色彩基调，禁止任何文字、字母、数字、logo", "alt": "一句话中文画面描述，12-20 字，给读屏和图片搜索用，不显示在正文"}
  ],
  "schema_type": "Article 或 FAQPage 或 HowTo",
  "faq": [{"question": "读者可能问的问题", "answer": "对应回答"}]
}
image_prompts 给 3 条：封面 + 两个 h2 章节。三条的画面主体必须完全不同（场景全景 / 人物动作 / 局部细节），拍摄角度轮换（平视 / 俯拍 / 特写），色彩基调不同（暖调 / 冷调 / 中性），禁止两张图描述相似构图。每条必须带 alt：一句人话（12-20 字），写画面里实际有什么，不要关键词堆叠、不要逗号分隔、不要品牌名/引号/序号/「配图」字样；alt 只用于图片替代文本与媒体库标题，不会显示在正文里。正文中禁止出现任何 <img> 标签。若输出被长度限制截断，优先保证 JSON 闭合与 content 完整，宁可少写一个 h2。
EOT;
    }

    /**
     * 获取默认用户提示词模板
     * 支持变量占位符：{{lang_name}}, {{prompt}}, {{word_count}}
     */
    public static function get_default_user_prompt() {
        return <<<'EOT'
请用{{lang_name}}写一篇文章，正文约{{word_count}}字。

【文章主题】{{prompt}}

【写作前先想清三件事（只在内部思考，不要输出）】
1. 读者是谁：久坐上班族 / 产后妈妈 / 想改善体态的塑形人群 / 完全零基础 / 正在选馆比价的人。只选一个最主要的人群，不要全都照顾到。
2. 她此刻的场景：一个能直接写进开头的画面（几点、在做什么、身体哪个位置不舒服）。
3. 她要的答案：她想确认「我是不是这种情况」，还是想知道「我该怎么做」，还是想验证「这个说法靠不靠谱」。按答案组织 h2。

【正文要求】
1. 开头 80 字内先给结论，不铺垫，不从「随着…」「在当今社会…」开始。
2. 3-5 个 h2，每个 h2 是她真会问的问题；每个 h2 下用 h3 拆 2-3 个角度，h3 用结论式短语命名，不用「第一点/第二点」。
3. 每个 h2 第一段直接回答问题；每章不少于 350 字，并给出至少一个今天就能做的动作或判断标准。
4. 段落 80-200 字；段落开头不用「这/那/其/该/此/因此/所以/此外/另外/例如/比如/首先」。
5. 只写有依据的内容：门店、课程、师资只用资料里给出的；没有数据就用具体场景、动作、时长代替，不编数字、不写价格、不承诺效果。
6. 健康边界：出现麻木或放射痛、肌力下降、大小便异常、夜间痛、发热、孕期或术后复杂情况，一律建议先就医。
7. 结尾先写「什么时候该先做评估或先就医」，再给一句下一步建议。
8. 主关键词在标题、首段、一个 h2、结尾各出现一次，不做密度堆砌。
9. 正文用 HTML（h2/h3/p/ul/li/strong），不要输出 <img> 标签。

【写完自查】
读者读完应该能说出三句话：① 这就是我的情况；② 我知道下一步做什么；③ 这家馆讲得比别人清楚。
EOT;
    }

    /**
     * 默认提示词的旧版指纹（规范化后 md5）。
     *
     * 用途：只清空「用户从未改过、用的就是出厂默认」的那份 system_prompt，
     * 让它回落到新版默认；用户亲手改过的提示词一律不动。
     * 空白差异（换行、缩进、首尾空格）在比对前全部剔除，避免保存一次就匹配不上。
     */
    const LEGACY_PROMPT_HASHES = array(
        'system_prompt'                 => array( '5185151a3e8f996a9f3f232b5588b8a7' ),
        'user_prompt'                   => array( '30579b8706e8dbee176a255fdc8c79f2' ),
        'single_generate_system_prompt' => array( '8782c83f020d19cd7a540483fcd508da' ),
    );

    /** 提示词版本：默认提示词已迁移过的标记，避免每次请求重复扫描 */
    const PROMPT_REV_OPTION = 'yimai_gso_prompt_rev';
    const PROMPT_REV        = '2026-11-27';

    /**
     * 升级默认提示词：把「出厂默认」清空，让 get() 回落到新版默认。
     *
     * 为什么必须清空而不是覆写：后台输入框的语义就是「留空=用默认」，
     * 一旦把长文本写进 option，它就成了「自定义」，以后每次改默认都失效。
     *
     * 为什么按指纹判断而不是按版本号：老站点可能保存过默认值，
     * 也可能被手工改过；只有指纹命中才说明「一字未动」，其余一律保留。
     */
    public static function maybe_upgrade_default_prompts() {
        if ( get_option( self::PROMPT_REV_OPTION ) === self::PROMPT_REV ) {
            return;
        }

        $legacy_markers = array(
            'system_prompt'                 => '双引擎内容策略师',
            'user_prompt'                   => '【写作前分析（仅供你内部思考，不需要输出）】',
            'single_generate_system_prompt' => 'SEO+GEO 双引擎撰稿人',
        );

        foreach ( self::LEGACY_PROMPT_HASHES as $field => $hashes ) {
            $option = 'yimai_gso_' . $field;
            $saved  = get_option( $option, null );
            if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
                continue;
            }

            $normalized = preg_replace( '/\s+/u', '', $saved );
            $is_legacy  = in_array( md5( $normalized ), $hashes, true );

            // 指纹兜底：保存时若被 sanitize 改动过（换行被压缩等），指纹会变，
            // 但老默认自带的特征串仍在，且长度接近原默认（说明不是重写过的自定义稿）。
            if ( ! $is_legacy && isset( $legacy_markers[ $field ] ) ) {
                $marker = $legacy_markers[ $field ];
                if ( false !== mb_strpos( $saved, $marker ) ) {
                    $is_legacy = true;
                }
            }

            if ( $is_legacy ) {
                delete_option( $option );
                yimai_gso_debug_log( '[一麦GSO 提示词升级] 已清空出厂默认的 ' . $field . '，回落新版默认' );
            }
        }

        update_option( self::PROMPT_REV_OPTION, self::PROMPT_REV, false );
    }

    /**
     * 合规硬约束：与「品牌事实硬约束」一样，由代码追加，不依赖用户是否改过提示词。
     */
    public static function get_compliance_block() {
        return "【健康与合规硬约束】\n" .
            "- 禁止出现「矫正、修复、治疗、根治、治好、康复治疗、排毒、疏通经络、包瘦、包好、几天见效」等表述；写成「改善、调整、训练配合、可见变化周期因人而异」。\n" .
            "- 禁止诊断与处方；出现麻木或放射痛、肌力明显下降、大小便异常、夜间痛醒、发热、孕期或术后复杂情况、持续加重的疼痛，一律先建议线下就医。\n" .
            "- 不写具体价格、优惠力度与套餐结构；不承诺效果与周期。\n" .
            "- 结尾必须写清「什么情况应该先做评估或先就医」，并给一句下一步建议。";
    }

    /**
     * 获取所有支持的 AI 提供商
     */
    public static function get_ai_providers() {
        return array(
            'openai'    => array(
                'name'    => 'OpenAI (GPT)',
                'url'     => 'https://api.openai.com/v1/chat/completions',
                'models'  => array( 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo' ),
            ),
            'deepseek'  => array(
                'name'    => 'DeepSeek（最便宜）',
                'url'     => 'https://api.deepseek.com/v1/chat/completions',
                'models'  => array( 'deepseek-chat', 'deepseek-reasoner' ),
            ),
            'qwen'      => array(
                'name'    => '阿里百炼（千问）',
                'url'     => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                'models'  => array( 'qwen-max', 'qwen-plus', 'qwen-turbo', 'qwen-long' ),
            ),
            'zhipu'     => array(
                'name'    => '智谱AI （GLM系列）',
                'url'     => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
                'models'  => array( 'glm-4', 'glm-4-flash', 'glm-3-turbo' ),
            ),
            'moonshot'  => array(
                'name'    => '月之暗面（Kimi）',
                'url'     => 'https://api.moonshot.cn/v1/chat/completions',
                'models'  => array( 'moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k' ),
            ),
            'ernie'     => array(
                'name'    => '百度千帆（文心）',
                'url'     => 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/wenxinworkshop/chat/completions',
                'models'  => array( 'ernie-4.0-8k', 'ernie-3.5-8k', 'ernie-speed-128k' ),
            ),
            'doubao'    => array(
                'name'    => '字节跳动（豆包）',
                'url'     => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
                'models'  => array( 'doubao-pro-32k', 'doubao-pro-128k', 'doubao-lite-32k' ),
            ),
            'tencent'   => array(
                'name'    => '腾讯大模型',
                'url'     => 'https://tokenhub.tencentmaas.com/v1/chat/completions',
                'models'  => array( 'deepseek-v4-pro', 'hunyuan-pro', 'hunyuan-standard' ),
            ),
            'siliconflow' => array(
                'name'    => '硅基流动',
                'url'     => 'https://api.siliconflow.cn/v1/chat/completions',
                'models'  => array( 'deepseek-ai/DeepSeek-V3', 'deepseek-ai/DeepSeek-R1', 'Qwen/Qwen2.5-72B-Instruct' ),
            ),
            'gemini'    => array(
                'name'    => 'Google Gemini',
                'url'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'models'  => array( 'gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash' ),
            ),
            'mistral'   => array(
                'name'    => 'Mistral',
                'url'     => 'https://api.mistral.ai/v1/chat/completions',
                'models'  => array( 'mistral-large-latest', 'mistral-medium-latest', 'open-mistral-7b' ),
            ),
            'claude'    => array(
                'name'    => 'Anthropic Claude',
                'url'     => 'https://api.anthropic.com/v1/messages',
                'models'  => array( 'claude-sonnet-4-20250514', 'claude-haiku-4-20250414', 'claude-3-5-sonnet-20241022' ),
            ),
            'moma'      => array(
                'name'    => '移动云 MoMA',
                'url'     => 'https://zhenze-huhehaote.cmecloud.cn/v1/chat/completions',
                'models'  => array( 'deepseek-v4-flash', 'deepseek-v4', 'deepseek-r1' ),
            ),
            'custom'    => array(
                'name'    => '自定义接口',
                'url'     => '',
                'models'  => array(),
            ),
        );
    }

    /**
     * 获取所有支持的配图提供商
     */
    public static function get_image_providers() {
        return array(
            'local'    => array(
                'name' => '我的图片库',
                'need_key' => false,
                'key_url'  => '',
            ),
            'pexels'   => array(
                'name' => 'Pexels',
                'need_key' => true,
                'key_url'  => 'https://www.pexels.com/api/',
            ),
            'unsplash' => array(
                'name' => 'Unsplash',
                'need_key' => true,
                'key_url'  => 'https://unsplash.com/developers',
            ),
            'pixabay'  => array(
                'name' => 'Pixabay',
                'need_key' => true,
                'key_url'  => 'https://pixabay.com/api/docs/',
            ),
            'flickr'   => array(
                'name' => 'Flickr',
                'need_key' => true,
                'key_url'  => 'https://www.flickr.com/services/apps/',
            ),
            'stockapi' => array(
                'name' => 'StockAPI',
                'need_key' => true,
                'key_url'  => 'https://stockapi.io/',
            ),
            'dalle'    => array(
                'name' => 'DALL-E',
                'need_key' => false,
                'key_url'  => '',
            ),
            'ai'       => array(
                'name'     => 'AI生成（豆包/智谱/万相）',
                'need_key' => true,
                'key_url'  => '',
            ),
            'mtphotos' => array(
                'name' => 'MT Photos（带水印素材库）',
                'need_key' => false,
                'key_url'  => '',
            ),
            'custom'   => array(
                'name' => '自定义图片API',
                'need_key' => true,
                'key_url'  => '',
            ),
        );
    }
}
