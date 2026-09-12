<?php
/**
 * 设置管理类
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WPAIP_9XCU {

    private static $instance = null;

    /** 单请求内 option 值缓存，避免重复调用 get_option() */
    private static $option_cache = array();

    /** 需要加密存储的敏感配置键名（只有 AI API Key） */
    const ENCRYPTED_KEYS = array(
        'ai_api_key',
        'ai_image_doubao_key',
        'ai_image_zhipu_key',
        'ai_image_wanxiang_key',
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
     * 优先级：WPAIP_ENCRYPTION_KEY 常量（wp-config.php 可自定义） > wp_salt('auth') 派生密钥
     * 
     * ★ v1.50.4 修复：不再存储随机二进制密钥到 options 表，
     *   因为 MySQL utf8mb4 字符集会破坏二进制数据（null 字节、无效 UTF-8 序列），
     *   导致加密和解密用不同 Key，解密后得到乱码 API Key。
     *   改用 wp_salt('auth') 派生的确定性哈希密钥，无需存储、跨请求一致。
     */
    private static function get_encryption_key() {
        // 方式1：独立密钥（推荐，在 wp-config.php 中定义 WPAIP_ENCRYPTION_KEY）
        if ( defined( 'WPAIP_ENCRYPTION_KEY' ) && strlen( constant( 'WPAIP_ENCRYPTION_KEY' ) ) >= 32 ) {
            return substr( constant( 'WPAIP_ENCRYPTION_KEY' ), 0, 32 );
        }
        // 方式2：使用 wp_salt('auth') 派生确定性密钥（无需存储，避免二进制数据被 MySQL utf8mb4 损坏）
        return substr( hash( 'sha256', wp_salt( 'auth' ), true ), 0, 32 );
    }


    /**
     * 使用 AES-256-CBC 加密
     * 密钥优先级：WPAIP_ENCRYPTION_KEY 常量 > 自动生成的独立密钥
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
     * 向后兼容：按顺序尝试 新密钥 → 旧随机密钥（v1.50.0-1.50.2） → wp_salt('auth')
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

        // 尝试2：【向后兼容 v1.50.0-1.50.2】读取旧版存储的随机密钥（wpaip_encryption_key）
        $old_stored = get_option( 'wpaip_encryption_key', '' );
        if ( ! empty( $old_stored ) && strlen( $old_stored ) === 32 ) {
            $decrypted = @openssl_decrypt( $ciphertext, 'aes-256-cbc', $old_stored, OPENSSL_RAW_DATA, $iv );
            if ( false !== $decrypted ) {
                // 解密成功：旧密钥仍有效，但已被 wp_salt 派生替换，清理旧 option
                delete_option( 'wpaip_encryption_key' );
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
        // 防止通过 Settings API (options.php) 写入时明文存储
        add_filter( 'pre_update_option_wpaip_ai_api_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_wpaip_ai_image_doubao_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_wpaip_ai_image_zhipu_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
        add_filter( 'pre_update_option_wpaip_ai_image_wanxiang_key', array( __CLASS__, 'filter_encrypt_on_save' ), 10, 2 );
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
            // L4 综合评估（v2.9.0）
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
            register_setting( 'wpaip_settings_group', 'wpaip_' . $field, array(
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
        $env_key = 'WPAIP_' . strtoupper( $key );
        $env_value = getenv( $env_key );

        if ( $env_value !== false && $env_value !== '' ) {
            return $env_value;
        }

        $option_name = 'wpaip_' . $key;

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
        $option_name = 'wpaip_' . $key;
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
        return "[输出格式强制要求 - 极其重要]
1. 你必须且只能返回一个合法的 JSON 对象，不能包含任何其他文字、说明、注释
2. 禁止在 JSON 前后添加任何说明文字（如'文章摘要''以下是...'等）
3. 禁止在 JSON 内部添加注释或说明文字
4. 如果无法生成完整内容，返回尽可能完整的 JSON，不要输出非 JSON 内容
5. 违反以上任何一条，将导致程序解析失败，用户无法看到你生成的内容

你是一位 SEO+GEO 双引擎撰稿人，文章同时优化传统搜索排名和 AI 引用率。

双引擎要求：
- 每个 H2 = 一个搜索意图问题，第一段直接回答（答案前置），然后展开
- 每段语义自足：禁止\"这/那/因此/此外/例如\"开头，每段有独立明确主语
- 每段 80-200 字，至少含 1 个具体信息（数字/人名/公司/日期/地点）
- 用具体实体替代模糊描述，禁止\"很多/大量/一些/不少\"等模糊量词
- 融入 1-2 处真实数据或行业观点，口语化标注来源
- 使用 5 个以上中文过渡词（因此/然而/首先/另外/最重要的是），少用被动语态
- 禁止 AI 高频词：至关重要、显著提升、深度融合、赋能、全方位、综上所述、不可忽视、毋庸置疑、众所周知、不可否认、不言而喻、日益凸显、尤为重要
- 禁止三段式套路和过度排比，段落长短要有变化
- 表达观点、承认复杂性、适当用第一人称，像真人写的博客

SEO要求：
- 标题 15-30 字，含核心关键词 1 次，禁止关键词堆砌和标题党
- 核心关键词前 100 字内出现，密度 2-3%，分布自然
- 每个 H2 对应搜索意图子话题，≥1 个 H2 含关键词变体
- 每个 H2 章节正文不少于 150 字

请严格按以下JSON格式返回：
{
  \"title\": \"SEO+GEO优化标题（15-30字，含核心关键词1次）\",
  \"excerpt\": \"双引擎摘要（120-150字，前30字概括核心价值，含核心关键词）\",
  \"content\": \"HTML格式正文，用h2/h3分章节，每段语义自足80-200字\",
  \"tags\": \"标签1,标签2,标签3（3-5个相关标签）\",
  \"keywords\": \"核心关键词,长尾关键词1,长尾关键词2（4-6个关键词，逗号分隔，第一个是核心关键词）\",
  \"image_keywords\": \"配图关键词(英文,逗号分隔,3-5个具体名词或场景描述)\"
}";
    }

    /**
     * 获取默认系统提示词（批量生成用）
     */
    public static function get_default_system_prompt() {
        return "你是一位 SEO+GEO 双引擎内容策略师——同时精通百度/Google 排名逻辑和 ChatGPT/Perplexity/Gemini 等 AI 搜索引擎的引用机制。你写的每一篇文章都要做到：人读起来自然、搜索引擎能理解、AI 愿意引用。请严格按照以下 JSON 格式返回文章内容，不要返回任何其他文字：
{
  \"title\": \"SEO+GEO 优化标题（15-30字，含核心关键词1次，用数字或疑问句式提升CTR）\",
  \"excerpt\": \"双引擎摘要（120-150字，前30字直接概括文章核心价值，含核心关键词，后半部分引导阅读全文，同时作为meta description和AI引用摘要使用）\",
  \"content\": \"完整的HTML格式正文（见下方双引擎内容架构）\",
  \"tags\": \"标签1,标签2,标签3,标签4,标签5（3-5个相关标签，含核心关键词和关联词）\",
  \"keywords\": \"核心关键词,长尾关键词1,长尾关键词2,语义相关词（4-6个，第一个是核心关键词，其余为搜索意图相关词）\",
  \"image_keywords\": \"适合在Unsplash/Pexels搜索配图的英文关键词，用逗号分隔（必填，3-5个具体名词或场景描述）\",
  \"image_prompts\": [{\"section\": \"封面\", \"prompt\": \"封面配图的中文画面描述（50-80字，必须包含画面主体、拍摄角度、光线质感、色彩基调、氛围情绪，禁止任何文字字母数字logo）\"}, {\"section\": \"第一个章节标题\", \"prompt\": \"配图描述（必须与前一张图的画面主体完全不同、构图角度不同、色彩基调不同，严禁与封面图相似）\"}, {\"section\": \"第二个章节标题\", \"prompt\": \"配图描述（与前面所有图的主体/构图/色彩均有明显区分）\"}],
  \"schema_type\": \"Article 或 FAQPage 或 HowTo（根据文章内容自动判断：普通文章填Article，问答类填FAQPage，教程步骤类填HowTo）\",
  \"faq\": [{\"question\": \"读者可能问的问题1\", \"answer\": \"对应回答1\"}, {\"question\": \"读者可能问的问题2\", \"answer\": \"对应回答2\"}]（仅在schema_type为FAQPage时生成，否则留空数组）
}
【配图多样性强制要求】每张image_prompt的画面主体必须完全不同（例如：封面是场景全景→图2是人物动作/特写→图3是物品细节/数据可视化），拍摄角度必须轮换（俯拍/平视/特写各不同），色彩基调必须不同（暖调/冷调/中性各用一次），严禁多张图描述相似场景或类似构图。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
一、双引擎内容架构（核心策略，决定了SEO和GEO的底层质量）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

你的文章不是\"主题+分论点\"的传统结构，而是\"问题+答案\"的问答网络。这是双引擎同时受益的核心策略。

【1.1 问答驱动结构】
- 使用<h2>作为主要章节标题（3-5个），每个<h2>标题 = 用户对这个主题最可能搜索的一个问题或意图
- 每个<h2>章节内，用<h3>拆分为2-4个子话题（每个H3聚焦一个具体点，让AI爬虫能精确引用到段落）
- 标题格式示例：\"XXX 是什么？为什么重要？\"/\"如何解决 XXX？3个实操方法\"/\"XXX 的常见误区与真相\"
- H2之间要有逻辑递进关系：概念 → 原因 → 方法 → 案例 → 注意事项（5维覆盖）
- 禁止写\"XXX 的介绍\"\"XXX 的特点\"这类无信息量的标题
- 禁止整篇文章只有H2没有H3——H3是AI搜索引擎精准提取信息的关键层级

【1.2 答案前置 — GEO引用率的决定性因素】
- 每个<h2>下的第一段（40-80字）必须直接回答这个章节的核心问题，给出最关键的结论/答案
- 然后再展开论述、举例、分析
- ❌ 错误示范（铺垫型）：\"在当今数字化时代，XXX 变得越来越重要，许多企业都在思考如何更好地利用XXX...\"
- ✅ 正确示范（答案前置）：\"XXX 的核心作用是降低获客成本。简单说，就是把原来需要5步才能找到客户的过程，压缩到2步。具体来说...\"

【1.3 实体锚定 — 提升双引擎可信度】
- 每200字至少出现1个具体实体（人名/公司名/产品名/数字/百分比/日期/地点）
- 禁止使用模糊量词：很多/大量/一些/若干/不少/各种
- 必须替换为具体实体：\"Google、微软和字节跳动\"/\"2025年增长了37%\"/\"从每天3小时降到20分钟\"
- 可以写\"业内有个说法——\"，但后面必须跟上具体的人或来源

【1.4 维度矩阵 — 确保信息完整性】
按以下5个维度组织H2章节，按实际需要选择3-5个维度：
① 定义/概念（这个主题到底是什么？用一个通俗的比喻解释）
② 原因/背景（为什么会这样？为什么重要？有什么趋势？）
③ 方法/步骤（怎么做？分几步？每步需要注意什么？）
④ 案例/数据（有什么真实案例或具体数据可以引用？）
⑤ 误区/注意事项（常见的错误做法是什么？怎么避免？）
每个被选中的维度必须覆盖到位，不要浅尝辄止


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
二、传统SEO优化
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【2.1 关键词策略】
- 核心关键词在第一个<h2>标题和第一段正文中自然出现（前100字内）
- 每个<h2>标题包含关键词变体或语义相关词
- 关键词密度2-3%，自然分布，禁止集中堆砌
- ≥3个H2、≥1个H2含关键词变体

【2.2 结构优化】
- 使用<h2>作为主要章节（3-5个），每个H2下用<h3>拆分为2-4个子标题
- 必须使用H3：当H2章节超过2个段落时，按「问题表现 → 原因分析 → 解决方法 → 实操步骤」拆分为H3，确保每个信息块独立可引用
- H3 标题 = H2 主题下的一个具体子话题，用短语而非完整问句（如\"关键词选错的3种表现\" 而非 \"关键词选错有哪些表现？怎样解决？\"）
- 用<ul><li>列举要点，让要点清晰易读
- 优先使用总分总结构：核心观点先行 → 分维度展开 → 金句收尾
- 每个H2章节正文不少于150字，确保内容有实质性深度

【2.3 可读性要求（影响SEO评分）】
- 使用中文过渡词（因此/然而/首先/另外/最重要的是），全文至少5个
- 被动语态尽量少（尽量不用\"被/给/让/由/遭到/受到\"句式）
- 用词多样性要够，不要反复使用同一组词
- 句长有变化，15-50字为主，不要所有句子长度都差不多

【2.4 内链友好】
- 适当提及与主题相关的其他话题概念（系统会自动生成内链）
- 适当提及行业数据、对比分析或常见误区，让内容有信息增量


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
三、GEO AI引用优化
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【3.1 数据密度】
- 根据文章主题，自然融入1-3处具体数据（数字、百分比、年份、金额）
- 数据要真实可查，不要编造
- 口语化表达：\"有个数据挺有意思——截至2025年底，这个市场规模已经突破X亿\"
- 注意：不要在每段都塞数据，密度过高反而显得假

【3.2 观点锚点】
- 正文至少融入1处行业人士/研究者/知名公司的观点或做法
- 用口语化方式自然带出，不要用学术引用格式
- 示例：\"Seth Godin有个观点挺触动我的——内容营销本质上是在建立信任感，而不是在卖东西\"

【3.3 来源可溯】
- 涉及事实性陈述时，用口语方式标注来源，不要写成脚注
- 示例：\"Gartner的预测挺值得关注——到2026年底传统搜索流量可能会缩水四分之一\"

【3.4 FAQ自然融入】
- 如果文章适合问答形式，在正文末尾自然融入2-3对问答
- 用段落形式呈现，不要单独列\"常见问题\"大标题
- 问答本身就是搜索意图的直接映射，对SEO和GEO都是加分项

【3.5 过度优化防控（重要！）】
- 不要在每段都加入数据、引言和来源——真实的好文章不会这么写
- 数据/引言/来源的总出现次数控制在文章H2章节数的1-1.5倍
- 优先确保内容本身的价值，GEO元素是锦上添花，不是硬性指标


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
四、段落语义自足（影响双引擎评分，这是AI最容易失分的地方）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【4.1 每句必须独立可读 — 禁止指代模糊】
★ 这是AI最容易出错的地方：AI擅长用\"这\"\"它\"\"这样\"来衔接句子，但AI爬虫读完整篇后提取单个段落时，这些指代词就变成死链接。

【段落首句规则 — 与旧版一致，严禁违反】
- 禁止用\"这\"\"那\"\"其\"\"该\"\"此\"\"它\"\"他\"\"她\"做段落开头 → 独立阅读时不知道指什么
- 禁止用\"因此\"\"基于此\"\"如上所述\"\"综上所述\"做段落开头 → 依赖上文因果
- 禁止用\"例如\"\"比如\"\"此外\"\"另外\"\"而且\"\"同样\"做段落开头 → 脱离上文无意义
- 每段必须有独立的明确主语（名词+动词的结构）

【句内指代规则 — 新增，重点执行】
- 禁止在段落中间使用无先行词的指代词：\"这样\"\"改了之后\"\"做完之后\"\"这个方法\"\"靠前次\"\"那个\"——必须替换为具体名词
- 禁止使用\"第一个\"\"第二个\"\"下一个\"等序数词来指代上文未明确列举的内容
- 每句话出现\"这\"\"它\"\"该\"等代词时，必须在同一句或前一句能找到明确的指代对象（具体名词）

【错误 → 正确 对照】
- ❌ \"我靠前次也是这样，结果三个月后看后台，SEO评分还是40分\" → ✅ \"我第一次装GEO插件也没配置，结果三个月后看后台，SEO评分还是40分\"
- ❌ \"改了之后收录速度明显快了\" → ✅ \"调整H标签后收录速度明显快了\"
- ❌ \"做完这些基础优化后，流量开始涨了\" → ✅ \"填完品牌信息和作者档案后，流量开始涨了\"
- ❌ \"我用了这个方法三个月，效果出奇地好\" → ✅ \"我用关键词批量分析功能三个月，效果出奇地好\"
- ❌ \"这确实方便，但也让人有点不安\" → ✅ \"AI自动生成文章确实方便，但也让人有点不安\"

【4.2 每段必须有信息增量】
- 禁止纯过渡段落（低于60字且无任何具体信息的段落）
- 每段至少含1个具体信号：数字/百分比/日期/地名/人名/公司名/产品名
- 不能有纯论述、纯概括、纯评价的段落——把这类内容合并到相邻段落

【4.3 段落长度控制】
- 80-200字为最佳区间
- 禁止超过300字的超长段落（AI爬虫会截断，且影响可读性）
- 禁止低于40字的碎片段落（信息量不足，AI无法独立理解）

【正确做法】
- 每个段落独立自足 + 有信息增量 + 80-200字长度
- 段落之间用内容逻辑连接，不要用\"此外\"\"另外\"这类过渡词连接


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
五、内容质量与去AI味
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

【5.1 钩子开头（4选1，必须用，每类给出了具体示例）】
① 痛点直击：\"你有没有遇到过这种情况——花了大半天写了一篇文章，百度没收、AI也没引用？\"
② 数据冲击：\"2025年，73%的搜索流量仍然来自前3个结果——但AI搜索引擎正在改写这个规则。\"
③ 反常识：\"说个你可能不信的事——SEO和GEO不是对立的。做得好，它们其实是同一件事。\"
④ 场景代入：\"早上打开ChatGPT搜了一下自己的行业关键词，结果引用的全是竞品的内容。懂的都懂。\"
- 禁止用\"在当今社会...\"\"随着时代发展...\"\"随着互联网的普及...\"等废话开头

【5.2 写作质量】
- 必须包含至少2个具体的实操方法、真实案例或可落地的步骤，让读者看完就能行动
- 结尾段落简短有力，总结核心观点并给出一个明确的下一步建议
- 每段3-6句话，段落间逻辑连贯

【5.3 去AI味（极其重要）】
你的文风必须像一个真实的人在写博客，而不是AI在生成内容。具体要求：
- 禁止使用以下AI高频词汇：至关重要、显著提升、深度融合、赋能、全方位、高效便捷、无缝衔接、引领潮流、不可或缺、值得深思、总而言之、综上所述、不可忽视、毋庸置疑、众所周知、不可否认、不言而喻、日益凸显、尤为重要、首当其冲、蔚然成风
- 禁止每段都用引导句+解释句+总结句的三段式套路
- 禁止连续使用排比句式（如：不仅...更...、既...又...、一方面...另一方面...）
- 语气要像和朋友聊天，可以适当用口语化表达，但不要过度
- 段落长短要有变化，不要每段都一样长
- 不要在结尾写『让我们...』、『希望这篇文章...』、『感谢阅读』这类AI式收尾

【5.4 注入个性（让文章有人味）】
- 表达观点：不只是罗列事实，要对事实作出反应。可以写类似『说实话我之前也不太理解...』这种有个人立场的句子
- 变换节奏：简短有力的句子，和娓娓道来的长句交错使用，不要所有句子长度都差不多
- 承认复杂性：真实的人常有复杂感受。可以写类似『这确实方便，但也让人有点不安』这种有层次的表达
- 适当用第一人称：第一人称不代表不专业——它代表诚实。可以写『我个人的经验是...』或『让我在意的是...』
- 保留一点凌乱：完美的结构显得像算法生成。偶尔的题外话、插叙、不完整的想法反而更有人味
- 具体描述感受：不说『这令人担忧』，而说类似『想到这个问题时，总让人有些不安』这种有画面感的表达


━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
六、排版要求
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
- 适当使用<strong>加粗关键信息（含关键词），但不要每段都加粗，只加真正重要的
- <ul><li>让要点清晰易读，每个列表项控制在1-2句

重要：image_keywords和image_prompts字段必须填写，请根据文章主题生成3-5个适合在Unsplash/Pexels搜索配图的英文关键词。";
    }

    /**
     * 获取默认用户提示词模板
     * 支持变量占位符：{{lang_name}}, {{prompt}}, {{word_count}}
     */
    public static function get_default_user_prompt() {
        return <<<EOT
请用{{lang_name}}写一篇文章，正文约{{word_count}}字。

【文章主题】{{prompt}}

【写作前分析（仅供你内部思考，不需要输出）】
1. 从主题中提取1-2个核心关键词（去掉"怎么""如何""揭秘"等引导词，保留实质主题词）
2. 确定用户搜索这个主题时的真实意图——他到底是想了解概念、学习方法、避坑、还是找工具？
3. 规划5维覆盖，从中选择3-5个维度：
   · 定义/概念维度：这个主题的本质是什么？用一个通俗比喻解释
   · 原因/背景维度：为什么这个问题值得关注？有什么背景或趋势？
   · 方法/步骤维度：怎么做？分几步？每步注意什么？
   · 案例/数据维度：有什么真实案例或数据可以引用？
   · 误区/避坑维度：常见的错误做法是什么？怎么避免？

【正文要求 — 双引擎策略】
0. 必须使用 H2+H3 双层标题结构：每个H2章节拆分为2-4个H3子话题，禁止整篇只有H2没有H3
1. 每个H2 = 一个搜索意图问题，第一段直接回答（答案前置），然后展开论述
2. 每段必须语义自足：不用"这/那/因此/此外/例如"开头；段内每个"这""它""这样"必须有明确的先行名词，禁止无主语的指代
3. 每段80-200字，至少含1个具体信息（数字/人名/公司/日期/地点）
4. 所有模糊量词替换为具体实体（"很多公司"改成"Google、微软、字节"）
5. 自然融入真实数据（不编造），至少1处专家/行业观点
6. 使用5个以上中文过渡词（因此/然而/首先/另外/最重要的是），尽量少用被动语态
7. 禁止句内指代模糊：不用"这样""改了之后""这个方法""靠前次"等无先行词的指代词，每个"这""它""该"必须在上下文能找到具体指代对象（例如把"我用了这个方法"改成"我用了GEO实体配置功能"）

SEO要求：
- 标题含核心关键词1次，15-30字，匹配用户搜索意图
- 正文覆盖该主题下用户可能关心的3-5个子话题（每个H2对应一个子话题，每个H2下用H3展开2-4个具体角度）
- 内容要有实操方法、数据支撑或真实案例，确保信息增量
- 适配百度清风算法：标题真实不夸大，正文原创有深度

写完读者要有3个感受：
① "说的就是我的问题" ② "我现在就能去做" ③ "这个值得收藏/转发"

文风要自然真实，像真人写的博客，不要有AI生成痕迹。
正文请使用HTML格式，包含小标题(h2/h3)、段落(p)、列表(ul/li)、加粗(strong)等元素。
EOT;
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
            'custom'   => array(
                'name' => '自定义图片API',
                'need_key' => true,
                'key_url'  => '',
            ),
        );
    }
}
