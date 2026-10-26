<?php
/**
 * AI 文章生成器
 * SEO+内容优化：强化内容实用性（实操方法/案例）、去AI味（禁高频词/反套路写法）
 * 支持生成、改写、摘要、标题、ALT文本等多种AI内容任务
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_AI_Generator {

    private $api_key;
    private $api_url;
    private $model;
    private $provider;

    public function __construct( $api_key = null, $api_url = null, $model = null, $provider = null ) {
        $this->provider = $provider ?: YIMAI_GSO_Settings::get( 'ai_provider', 'openai' );
        $this->api_key  = $api_key ?: YIMAI_GSO_Settings::get( 'ai_api_key', '' );
        $this->model    = $model ?: YIMAI_GSO_Settings::get( 'ai_model', 'gpt-4o' );

        // 确定 API URL
        if ( $api_url ) {
            $this->api_url = $api_url;
        } else {
            $providers     = YIMAI_GSO_Settings::get_ai_providers();
            $this->api_url = YIMAI_GSO_Settings::get( 'ai_api_url' )
                ?: ( $providers[ $this->provider ]['url'] ?? 'https://api.openai.com/v1/chat/completions' );
        }
    }

    /**
     * 生成完整文章（标题+正文+摘要+标签+SEO关键词）
     */
    public function generate_article( $prompt, $word_count = 1000, $language = 'zh_CN', $article_title = '' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            $prompt = YIMAI_GSO_Knowledge_Center::augment_prompt( $prompt, $article_title );
        }

        $lang_name   = $language === 'zh_CN' ? '中文' : 'English';
        $sys_prompt  = YIMAI_GSO_Settings::get( 'system_prompt' );
        if ( empty( $sys_prompt ) ) {
            $sys_prompt = "【输出格式强制要求 - 极其重要】\n" .
                          "1. 你必须且只能返回一个合法的 JSON 对象，不能包含任何其他文字、说明、注释\n" .
                          "2. 禁止在 JSON 前后添加任何说明文字（如'文章摘要''以下是...'等）\n" .
                          "3. 禁止在 JSON 内部添加注释或说明文字\n" .
                          "4. 如果无法生成完整内容，返回尽可能完整的 JSON，不要输出非 JSON 内容\n" .
                          "5. 违反以上任何一条，将导致程序解析失败，用户无法看到你生成的内容\n\n" .
                          YIMAI_GSO_Settings::get_default_system_prompt() .
                          "\n\n八、禁止生成图片标签\n" .
                          "- 正文中严禁输出任何 <img> 标签，图片由系统自动配图，你只需要输出纯文字内容\n" .
                          "- 即使你找到了相关图片，也不要在正文中写 <img> 标签，系统会统一处理配图";
        }
        $sys_prompt .= "\n\n【一麦瑜伽品牌事实硬约束】除非用户明确要求竞品对比，否则不得推荐或虚构其他品牌、机构或门店；涉及一麦瑜伽的名称、服务和地址只能使用提示词中的 canonical 实体与资料事实。";

        $user_prompt_tpl = YIMAI_GSO_Settings::get( 'user_prompt' );
        if ( empty( $user_prompt_tpl ) ) {
            $user_prompt_tpl = YIMAI_GSO_Settings::get_default_user_prompt();
        }

        $user_message = str_replace(
            array( '{{lang_name}}', '{{prompt}}', '{{word_count}}' ),
            array( $lang_name, $prompt, $word_count ),
            $user_prompt_tpl
        );

        // 动态计算 max_tokens：中文字约 1.5~2.5 token/字（模型差异大），JSON 结构开销约 500~800 tokens
        // 系数从 4 提升至 6（中文 + HTML 标签 + JSON 包装开销更高），下限 4096，上限 16384
        // 留足余量：1500 字 = 9000 + 3000 = 12000 tokens，避免「文章末尾停在提问上」的截断
        $dynamic_max_tokens = max( 4096, min( 16384, (int) ( $word_count * 6 ) + 3000 ) );

        // 字数与完整性硬约束：模型常为了「约 X 字」主动收尾，导致最后一个问题只有提问没有答案
        $user_message .= "\n\n【字数与完整性硬要求】目标约{$word_count}字，允许上下浮动 20%（约 "
            . (int) ( $word_count * 0.8 ) . '–' . (int) ( $word_count * 1.2 )
            . " 字）；完整性优先于字数——宁可略微超出，也不要把任何小标题写成只剩提问或半句话；每个 H2/H3 必须写出完整答案；全篇结尾必须有一句总结收束，禁止以提问或未完成的句子结束。";

        $response = $this->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user',   'content' => $user_message ),
        ), $dynamic_max_tokens );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? '';

        // 解析 JSON 响应
        $content_raw = $content;

        // 检查 AI 返回是否因 max_tokens 不足被截断（finish_reason === 'length'）
        $finish_reason = $response['choices'][0]['finish_reason'] ?? '';
        $is_truncated  = ( 'length' === $finish_reason );

        // 增强 JSON 提取：先尝试从返回内容中提取纯 JSON 部分（应对 AI 在 JSON 前后加说明文字的情况）
        $article = $this->extract_and_decode_json( $content );

        // 如果第一次提取失败，尝试清理 markdown 标记后再提取
        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            $content_clean = preg_replace( '/^```json\s*/m', '', $content );
            $content_clean = preg_replace( '/^```\s*/m', '', $content_clean );
            $content_clean = trim( $content_clean );
            $article = $this->extract_and_decode_json( $content_clean );
        }

        // 关键修复：如果被截断，即使 JSON"解析成功"也要丢弃（截断位置可能正好在字符串内，导致内容不完整）
        if ( $is_truncated ) {
            // 尝试修复被截断的 JSON
            $repaired = $this->try_repair_truncated_json( $content_clean ?? $content );
            if ( is_array( $repaired ) && ! empty( $repaired['title'] ) ) {
                $article = $repaired;
            } else {
                // 修复失败，强制走 fallback
                $article = null;
            }
        }

        // 兜底修复：很多中转网关/免费模型不返回 finish_reason，输出中途断掉却仍报 'stop'。
        // 只要 JSON 解析失败，就无条件尝试一次「补全截断 JSON」，不再只依赖 finish_reason。
        if ( ( ! is_array( $article ) || empty( $article['title'] ) ) ) {
            $repaired = $this->try_repair_truncated_json( $content_clean ?? $content );
            if ( is_array( $repaired ) && ! empty( $repaired['title'] ) ) {
                $article      = $repaired;
                $is_truncated = true;
            }
        }

        // 最终检查：JSON 解析失败或内容无效时走 fallback
        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            // 从原始内容中提取纯文本内容作为兜底
            $fallback_content = $this->extract_plain_text_from_ai_response( $content_raw );

            // 安全：移除 AI 可能生成在正文中的 <img> 标签
            $safe_content = preg_replace( '/<img\b[^>]*\/?>/i', '', $fallback_content );

            // 防护：兜底文本如果明显是提示词回显（含指令特征词），宁可失败也不要把提示词发成文章
            if ( $this->looks_like_prompt_echo( $safe_content ) ) {
                return new WP_Error(
                    'prompt_echo',
                    'AI 未返回有效 JSON 且疑似回显提示词，已拒绝生成。请检查模型是否可用或稍后重试。'
                );
            }

            // 防护：提取不到正文（空，或仍是 JSON 骨架）时明确报错，
            // 不再把 JSON 片段当文章返回——那会让前端显示乱码、字数统计失真、HTML 预览空白。
            if ( mb_strlen( trim( (string) $safe_content ) ) < 20 ) {
                return new WP_Error(
                    'ai_truncated',
                    '模型输出不完整（疑似在 JSON 中途被截断），未能提取正文。请重试；若反复出现，请在「设置 → AI配置」改用支持长输出的模型（推荐 DeepSeek），或把目标字数调小。'
                );
            }

            // 回退时用干净的 article_title 做 title，绝不能用包含补充要求的 raw prompt
            $fallback_title = ! empty( $article_title ) ? $article_title : $prompt;
            // 如果回退 title 也含补充要求，截断清洗
            $fallback_title = $this->clean_title( $fallback_title );

            return array(
                'title'          => $fallback_title,
                'excerpt'        => mb_substr( strip_tags( $safe_content ), 0, 100 ),
                'content'        => $safe_content,
                'tags'           => '',
                'keywords'       => $fallback_title,
                'image_keywords' => $this->local_keyword_mapping( $fallback_title ),
                'image_prompts'  => array(),
                'raw'            => $content_raw,
                'truncated'      => $is_truncated,
            );
        }

        // 修复部分模型（如智谱AI）返回 JSON 后 content/excerpt 字段含字面量 \n \t 的问题：
        // 这些模型有时将换行/制表符双重转义，json_decode 后变成字面 "\n" "\t" 字符串，
        // 发布后会在文章中显示为 "nn" "nt" 等乱码。统一做一次反转义处理。
        foreach ( array( 'content', 'excerpt', 'title' ) as $text_field ) {
            if ( ! empty( $article[ $text_field ] ) && is_string( $article[ $text_field ] ) ) {
                $article[ $text_field ] = str_replace(
                    array( '\\n', '\\t', '\\r' ),
                    array( "\n",  "\t",  "\r"  ),
                    $article[ $text_field ]
                );
            }
        }

        // 安全：移除 AI 生成的正文中的 <img> 标签（防止外部图片被 download_and_replace_images 下载）
        if ( ! empty( $article['content'] ) && is_string( $article['content'] ) ) {
            $article['content'] = preg_replace( '/<img\b[^>]*\/?>/i', '', $article['content'] );
        }

        // 如果 image_keywords 为空，用本地关键词映射（避免额外 API 调用）
        if ( empty( $article['image_keywords'] ) ) {
            $article['image_keywords'] = $this->local_keyword_mapping( $article['keywords'] ?? $prompt );
        }

        // tags 字段容错处理：支持数组、换行分隔、空值自动从 keywords 提取
        $article['tags'] = $this->normalize_tags_field( $article, $prompt );

        // 清理 content 字段末尾可能混入的 JSON 残留数据（第二篇文章开始出现的问题）
        if ( ! empty( $article['content'] ) && is_string( $article['content'] ) ) {
            $article['content'] = $this->clean_json_residue_from_content( $article['content'] );
        }

        // ★ 自动续写：批量/自动发布同样存在「接近目标字数就主动收尾」的问题，
        //   结尾只剩提问或半句话时自动续写（最多 2 轮）。
        $article = $this->maybe_continue_content( $article, $word_count, $sys_prompt, $user_message );

        // 表格补内联样式：主题通常不给文章内 table 加边框，导致表格「没有框」
        if ( ! empty( $article['content'] ) && class_exists( 'YIMAI_GSO_Publisher' ) ) {
            $article['content'] = YIMAI_GSO_Publisher::normalize_table_styles( $article['content'] );
        }

        return $article;
    }

    /**
     * 本地关键词映射（中文关键词 → 英文搜索词）
     * 替代原来的 translate_keywords()，避免每次额外调用一次 AI API（省15-30秒）
     */
    /**
     * 判断文本是否疑似「提示词回显」——即 AI 把系统/用户提示词当成正文返回
     * 命中任一强特征即视为提示词泄漏，兜底逻辑应当拒绝而不是发文
     */
    private function looks_like_prompt_echo( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) {
            return false;
        }
        $needles = array(
            '输出格式强制要求',
            '你需要生成一个JSON对象',
            '必须使用H2+H3结构',
            'schema_type',
            'image_prompts',
            '品牌事实硬约束',
            'canonical 实体',
            '禁止在 JSON 前后添加任何说明文字',
            '正文中严禁输出任何 <img> 标签',
            'image_keywords',
            'faq数组',
            '答案前置',
        );
        foreach ( $needles as $n ) {
            if ( false !== mb_strpos( $text, $n ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 清洗标题中的 prompt 指令污染（补充要求、字数要求等回退时可能泄漏到标题中）
     */
    private function clean_title( $title ) {
        $title = trim( (string) $title );
        // 按常见泄漏分隔符截断，只保留第一个句子之前的部分
        $cut_patterns = array(
            '/\s*(补充要求|additional_requirements|字数要求|你必须输出|如果字数不足|字数达标|请继续展开|以上为|请输出|要求输出|输出要求)\b/u',
            '/\n{2,}.*$/s',    // 双换行后的所有内容
        );
        foreach ( $cut_patterns as $pat ) {
            if ( preg_match( $pat, $title, $m, PREG_OFFSET_CAPTURE ) ) {
                $title = trim( substr( $title, 0, $m[0][1] ) );
                break;
            }
        }
        return $title;
    }

    private function local_keyword_mapping( $keywords ) {
        static $map = null;
        if ( null === $map ) {
            $map = array(
                '自媒体' => 'social media content creation',
                '副业' => 'side hustle freelance',
                '创业' => 'startup entrepreneurship',
                '短视频' => 'short video production',
                '带货' => 'ecommerce live streaming',
                '变现' => 'monetization passive income',
                '赚钱' => 'making money online',
                '流量' => 'web traffic growth',
                '粉丝' => 'social media followers',
                '爆款' => 'viral content trending',
                '引流' => 'lead generation marketing',
                '课程' => 'online course education',
                '知识付费' => 'knowledge monetization',
                '个人品牌' => 'personal branding',
                '运营' => 'social media operations',
                '涨粉' => 'grow followers audience',
                'AI' => 'artificial intelligence technology',
                '人工智能' => 'artificial intelligence AI',
                'ChatGPT' => 'chatgpt openai chatbot',
                '职场' => 'workplace career office',
                '技能' => 'professional skills learning',
                '效率' => 'productivity efficiency tools',
                '时间管理' => 'time management productivity',
                '编程' => 'programming coding developer',
                '设计' => 'graphic design creative',
                '营销' => 'digital marketing strategy',
                '文案' => 'copywriting content creation',
                'SEO' => 'SEO search optimization',
                '电商' => 'ecommerce online shopping',
                '健康' => 'healthy lifestyle wellness',
                '理财' => 'financial planning investment',
                '投资' => 'investment finance stock',
                '减肥' => 'weight loss fitness exercise',
                '美食' => 'food cooking delicious',
                '旅游' => 'travel adventure explore',
                '教育' => 'education learning study',
                '科技' => 'technology digital innovation',
                '互联网' => 'internet digital technology',
                '技巧' => 'tips tricks techniques',
                '方法' => 'methods strategies guide',
                '指南' => 'guide tutorial howto',
                '趋势' => 'trends future forecast',
                '经验' => 'experience lessons learned',
                '避坑' => 'mistakes to avoid pitfalls',
                '工具' => 'tools software resources',
            );
        }

        $input = trim( $keywords );
        $english_parts = array();

        // 先尝试匹配长词（避免"知识付费"先匹配到"知识"）
        $sorted_keys = array_keys( $map );
        usort( $sorted_keys, function ( $a, $b ) {
            return ( function_exists( 'mb_strlen' ) ? mb_strlen( $b ) : strlen( $b ) ) - ( function_exists( 'mb_strlen' ) ? mb_strlen( $a ) : strlen( $a ) );
        });

        foreach ( $sorted_keys as $cn ) {
            if ( ( function_exists( 'mb_strpos' ) ? mb_strpos( $input, $cn ) : strpos( $input, $cn ) ) !== false ) {
                $english_parts[] = $map[ $cn ];
            }
        }

        if ( ! empty( $english_parts ) ) {
            return implode( ', ', array_unique( $english_parts ) );
        }

        return 'business, technology, lifestyle, creativity';
    }

    /**
     * 规范化 tags 字段：支持数组、换行分隔、空值自动从 keywords 提取
     */
    private function normalize_tags_field( $article, $prompt ) {
        $tags = $article['tags'] ?? '';

        // 如果是数组，转为逗号分隔字符串
        if ( is_array( $tags ) ) {
            $tags = implode( ',', array_map( 'trim', $tags ) );
        }

        // 如果是字符串，清理换行符和多余空格
        if ( is_string( $tags ) ) {
            $tags = str_replace( array( "\r\n", "\n", "\r", '、', '/', '|', '，' ), ',', $tags );
            $tags = preg_replace( '/\s*,\s*/', ',', $tags );
            $tags = trim( $tags, ', ' );
        }

        // 如果 tags 仍然为空，从 keywords 字段提取前3个作为标签
        if ( empty( $tags ) ) {
            $keywords = $article['keywords'] ?? $prompt;
            if ( is_string( $keywords ) && ! empty( $keywords ) ) {
                $kw_parts = array_map( 'trim', explode( ',', $keywords ) );
                $kw_parts = array_filter( $kw_parts );
                $tags = implode( ',', array_slice( $kw_parts, 0, 3 ) );
            }
        }

        //  修复：原来兜底用 $prompt（即文章标题），导致标题被当标签插入。
        // AI 无 tags/keywords 时不强制赋值，留给下游 build_tags() 用 keywords/主题词做 fallback。
        // 不再用 prompt（标题）当标签。

        // 限制最多 5 个标签
        if ( is_string( $tags ) && ! empty( $tags ) ) {
            $tag_parts = array_map( 'trim', explode( ',', $tags ) );
            $tag_parts = array_filter( $tag_parts );
            $tags = implode( ',', array_slice( $tag_parts, 0, 5 ) );
        }

        return $tags;
    }

    /**
     * 清理 content 字段末尾可能混入的 JSON 残留数据
     * 某些 AI 模型在生成长文本时，可能将后续 JSON 字段数据混入 content 字段末尾
     * 例如：...",\n"tags": "...",\n"keywords": "...",\n"schema_type": "Article"\n}"
     * 这种情况在第二篇文章开始出现，需要清理掉这些残留
     *
     * @param string $content HTML 正文内容
     * @return string 清理后的正文内容
     */
    private function clean_json_residue_from_content( $content ) {
        $original_content = $content;

        // 匹配 content 末尾混入的 JSON 字段数据
        // 同时支持直引号(" ')和弯引号(" "、「 」)，使用非贪婪匹配避免误伤正文
        // 模式1：直引号
        $pattern1 = '/,?\s*[\"\']tags[\"\']\s*:\s*[\"\'].*?[\"\']\s*,?\s*[\"\']keywords[\"\']\s*:\s*[\"\'].*?[\"\']\s*,?\s*[\"\']image_keywords[\"\']\s*:\s*[\"\'].*?[\"\']\s*,?\s*[\"\']schema_type[\"\']\s*:\s*[\"\'].*?[\"\']\s*\n?\s*}$/is';
        // 模式2：弯引号（全角/中文引号）
        $pattern2 = '/,?\s*[「"]tags[「"]\s*[：:]\s*[「"].*?[「"]\s*,?\s*[「"]keywords[「"]\s*[：:]\s*[「"].*?[「"]\s*,?\s*[「"]image_keywords[「"]\s*[：:]\s*[「"].*?[「"]\s*,?\s*[「"]schema_type[「"]\s*[：:]\s*[「"].*?[「"]\s*\n?\s*}$/is';

        $cleaned = preg_replace( $pattern1, '', $content );
        if ( $cleaned === $content ) {
            $cleaned = preg_replace( $pattern2, '', $content );
        }

        // 如果正则没有匹配到内容（说明不是这种格式），返回原内容
        if ( null === $cleaned || $cleaned === $content ) {
            return $content;
        }

        // 清理末尾可能的逗号、换行等
        $cleaned = rtrim( $cleaned, ",\n \t" );
        $cleaned = rtrim( $cleaned, "\n," );

        // 安全校验：如果清理后内容过短（<100字符），说明可能误删了正文，返回原内容
        if ( mb_strlen( strip_tags( $cleaned ) ) < 100 && mb_strlen( strip_tags( $content ) ) > 500 ) {
            return $content;
        }

        return $cleaned;
    }

    /**
     * 清理字符串中的控制字符和零宽字符
     * 防止 AI 返回的内容中出现 <0x01> 等乱码符号
     *
     * @param string $text 原始文本
     * @return string 清理后的文本
     */
    private function sanitize_control_chars( $text ) {
        // 纯字符级过滤，不依赖 preg_replace，彻底杜绝 PHP 8.x null 参数 deprecated 警告
        if ( $text === null || $text === '' || ! is_string( $text ) ) {
            return '';
        }
        $len    = strlen( $text );
        $result = '';
        for ( $i = 0; $i < $len; $i++ ) {
            $ord = ord( $text[ $i ] );
            // 保留：制表符(9)、换行(10)、回车(13)、可打印字符(32-126)、高位字节(>=127，UTF-8多字节)
            if ( $ord === 0x09 || $ord === 0x0A || $ord === 0x0D || $ord >= 0x20 ) {
                $result .= $text[ $i ];
            }
        }
        return $result;
    }

    /**
     * 计算两段文本的相似度（基于相似字符比例）
     * 用于检测 AI 改写是否有效
     *
     * @param string $text1 原文
     * @param string $text2 改写后的文本
     * @return float 相似度百分比（0-100）
     */
    private function calculate_similarity( $text1, $text2 ) {
        if ( empty( $text1 ) || empty( $text2 ) ) {
            return 0;
        }
        $text1 = preg_replace( '/\s+/', '', $text1 );
        $text2 = preg_replace( '/\s+/', '', $text2 );

        // 字符级相似度：用 n-gram 哈希对比，避免 UTF-8 字节级 similar_text 高估中文相似度
        $n = 3; // 3-gram 字符窗口
        $grams1 = array();
        $grams2 = array();

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $len1 = mb_strlen( $text1 );
            $len2 = mb_strlen( $text2 );
            for ( $i = 0; $i <= $len1 - $n; $i++ ) {
                $gram = mb_substr( $text1, $i, $n );
                $grams1[ $gram ] = ( $grams1[ $gram ] ?? 0 ) + 1;
            }
            for ( $i = 0; $i <= $len2 - $n; $i++ ) {
                $gram = mb_substr( $text2, $i, $n );
                $grams2[ $gram ] = ( $grams2[ $gram ] ?? 0 ) + 1;
            }
        } else {
            $len1 = strlen( $text1 );
            $len2 = strlen( $text2 );
            for ( $i = 0; $i <= $len1 - $n; $i++ ) {
                $gram = substr( $text1, $i, $n );
                $grams1[ $gram ] = ( $grams1[ $gram ] ?? 0 ) + 1;
            }
            for ( $i = 0; $i <= $len2 - $n; $i++ ) {
                $gram = substr( $text2, $i, $n );
                $grams2[ $gram ] = ( $grams2[ $gram ] ?? 0 ) + 1;
            }
        }

        // Jaccard-like n-gram 相似度
        $intersect = 0;
        $union_total = 0;
        $all_grams = $grams1;
        foreach ( $grams2 as $gram => $count ) {
            $all_grams[ $gram ] = max( $all_grams[ $gram ] ?? 0, $count );
        }
        foreach ( $all_grams as $gram => $max_count ) {
            $c1 = $grams1[ $gram ] ?? 0;
            $c2 = $grams2[ $gram ] ?? 0;
            $intersect += min( $c1, $c2 );
            $union_total += $max_count;
        }

        return $union_total > 0 ? ( $intersect / $union_total ) * 100 : 0;
    }

    /**
     * 从 AI 返回内容中提取并解析 JSON
     * 应对 AI 在 JSON 前后加说明文字的情况（如"文章摘要"等）
     *
     * @param string $content AI 返回的原始内容
     * @return array|null 解析成功返回数组，失败返回 null
     */
    private function extract_and_decode_json( $content ) {
        // 防护：传入 null 或空值时直接返回 null，避免 preg_replace 触发 PHP 8.x deprecated 警告
        if ( ! is_string( $content ) || trim( $content ) === '' ) {
            return null;
        }
        // 先清理控制字符（0x00-0x1F 除换行/制表符外），避免 JSON 解析失败或乱码
        $content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );

        // 方法1：直接解析（如果整个内容就是纯 JSON）
        $article = json_decode( $content, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $article ) ) {
            return $article;
        }

        // 方法2：提取第一个 { 到最后一个 } 之间的内容
        if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
            $json_str = $matches[0];
            $article = json_decode( $json_str, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $article ) ) {
                return $article;
            }
        }

        // 方法3：更宽松的提取，处理嵌套结构
        $start = strpos( $content, '{' );
        $end = strrpos( $content, '}' );
        if ( false !== $start && false !== $end && $end > $start ) {
            $json_str = substr( $content, $start, $end - $start + 1 );
            $article = json_decode( $json_str, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $article ) ) {
                return $article;
            }
        }

        return null;
    }

    /**
     * 从 AI 返回内容中提取纯文本内容（去除 JSON 结构）
     * 当 JSON 解析失败时，尝试提取有意义的文本内容
     * 
     * @param string $content AI 返回的原始内容
     * @return string 提取的纯文本内容
     */
    private function extract_plain_text_from_ai_response( $content ) {
        // 防护：传入 null 或空值时直接返回空字符串，避免 preg_match/preg_replace 触发 PHP 8.x deprecated 警告
        if ( ! is_string( $content ) || trim( $content ) === '' ) {
            return '';
        }

        // ① 完整闭合的 content 字段
        if ( preg_match( '/"content"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches ) ) {
            $extracted = $this->unescape_json_like_text( $matches[1] );
            if ( '' !== trim( $extracted ) ) {
                return $extracted;
            }
        }

        // ② 被截断的 content 字段（无闭合引号）：从 "content": " 之后取到末尾
        //    这是「模型输出中途被截断」最常见的情形，原实现匹配不到闭合引号，
        //    会一路落到「返回原始内容前 2000 字」，把整段 JSON 骨架当成正文发给前端。
        if ( preg_match( '/"content"\s*:\s*"(.*)$/s', $content, $matches ) ) {
            $body = $this->unescape_json_like_text( $matches[1] );
            $body = $this->trim_dangling_json_tail( $body );
            if ( mb_strlen( trim( $body ) ) >= 20 ) {
                return trim( $body );
            }
        }

        // ③ 无 content 字段：清掉 JSON 结构行
        $text = preg_replace( '/^\s*"[^"]*"\s*:\s*"[^"]*"\s*,?\s*$/m', '', $content );
        $text = preg_replace( '/^\s*[{}\[\]]\s*,?\s*$/m', '', (string) $text );
        $text = preg_replace( '/^```(?:json)?\s*$/m', '', (string) $text );
        $text = trim( (string) $text );

        // ④ 若清理后仍像 JSON 骨架（以 { 开头或含 "title": 结构），判定为无效内容，返回空
        if ( '' === $text || preg_match( '/^\s*\{?\s*"(?:title|excerpt|content)"\s*:/i', $text ) ) {
            return '';
        }

        if ( mb_strlen( $text ) > 100 ) {
            return $text;
        }

        // ⑤ 最后的兜底：只有在内容不像 JSON 时才截断返回
        $plain = trim( mb_substr( strip_tags( $content ), 0, 2000 ) );
        return preg_match( '/^\s*\{?\s*"(?:title|excerpt|content)"\s*:/i', $plain ) ? '' : $plain;
    }

    /**
     * 反转义 JSON 字符串内容（不依赖 json_decode，可用于已截断的片段）
     */
    private function unescape_json_like_text( $value ) {
        $value = (string) $value;
        // \uXXXX → 真正的 UTF-8 字符（emoji、特殊标点等不能丢）
        $value = preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', function ( $m ) {
            $code = hexdec( $m[1] );
            $char = function_exists( 'mb_convert_encoding' )
                ? mb_convert_encoding( pack( 'n', $code ), 'UTF-8', 'UTF-16BE' )
                : '';
            return ( is_string( $char ) && '' !== $char ) ? $char : '';
        }, $value );
        $value = str_replace( array( '\\n', '\\t', '\\r' ), array( "\n", "\t", "\r" ), $value );
        $value = str_replace( array( '\\"', '\\\\', '\\/' ), array( '"', '\\', '/' ), $value );
        return trim( $value );
    }

    /**
     * 去掉截断处残留的 JSON 尾巴（如 `","tags":["a","b`）
     */
    private function trim_dangling_json_tail( $body ) {
        $body = (string) $body;
        // 形如  ...正文","tags":"..."  或  ...正文","image_keywords":[...  → 截掉
        $body = preg_replace( '/",\s*"[a-zA-Z_]+"\s*:.*$/s', '', $body );
        // 形如  ...正文"},  → 截掉
        $body = preg_replace( '/"?\s*\}\s*\]?\s*$/s', '', $body );
        // 形如  ...正文",  （只剩引号+逗号）
        $body = preg_replace( '/"?\s*,\s*$/s', '', $body );
        return trim( (string) $body );
    }

    /**
     * 判断正文是否「没写完」：末尾停在提问、缺收尾标点、或字数远低于目标
     */
    private function is_content_incomplete( $content, $word_count ) {
        $plain = trim( wp_strip_all_tags( (string) $content ) );
        if ( '' === $plain ) {
            return true;
        }
        $len = mb_strlen( $plain );
        if ( $len < max( 200, (int) ( $word_count * 0.55 ) ) ) {
            return true;
        }
        $tail = mb_substr( $plain, -32 );
        // 以问号结尾 = 抛出问题但没有作答
        if ( preg_match( '/[？?]\s*$/u', $tail ) ) {
            return true;
        }
        // 末尾没有句末标点 = 半句话
        if ( ! preg_match( '/[。！？…"」』）)】]\s*$/u', $tail ) ) {
            return true;
        }
        return false;
    }

    /**
     * 自动续写不完整正文（最多 2 轮）。
     * 模型按「约 X 字」的指令常主动收尾，把最后一个小标题写成只有提问没有答案；
     * token 预算再高也挡不住这种「主动停」。这里用续写补全，保证文章完整。
     */
    private function maybe_continue_content( $article, $word_count, $system_content, $user_message ) {
        if ( ! is_array( $article ) || empty( $article['content'] ) ) {
            return $article;
        }
        for ( $round = 0; $round < 2; $round++ ) {
            if ( ! $this->is_content_incomplete( $article['content'], $word_count ) ) {
                break;
            }
            $messages = array(
                array( 'role' => 'system', 'content' => (string) $system_content ),
                array( 'role' => 'user',   'content' => (string) $user_message ),
                array( 'role' => 'assistant', 'content' => (string) $article['content'] ),
                array( 'role' => 'user', 'content' => '上面的正文在中途断了。请直接从断点处继续写完剩余内容（只输出 HTML 片段，不要重复已有段落，不要重写标题），并在最后补一段简短总结收束全文。' ),
            );
            $resp = $this->call_api( $messages, 4096 );
            if ( is_wp_error( $resp ) ) {
                break;
            }
            $append = (string) ( $resp['choices'][0]['message']['content'] ?? '' );
            $append = preg_replace( '/^```(?:html|json)?\s*/m', '', $append );
            $append = preg_replace( '/```\s*$/m', '', (string) $append );
            $append = trim( (string) $append );
            if ( '' === $append ) {
                break;
            }
            // 追加内容若被包成 JSON，抽出正文
            if ( preg_match( '/^\s*\{/', $append ) ) {
                $extracted = $this->extract_plain_text_from_ai_response( $append );
                if ( '' !== $extracted ) {
                    $append = $extracted;
                }
            }
            $article['content'] = rtrim( (string) $article['content'] ) . "\n" . $append;
            $article['truncated'] = false;
            $article['continued'] = true;
        }
        return $article;
    }

    /**
     * 尝试修复因 max_tokens 截断导致的不完整 JSON
     * 策略：补全缺失的闭合括号和引号
     */
    private function try_repair_truncated_json( $content ) {
        // 防护：传入 null 或空值时直接返回 null
        if ( ! is_string( $content ) || trim( $content ) === '' ) {
            return null;
        }
        // 统计未闭合的括号和引号
        $stack = array();
        $in_string = false;
        $escape = false;
        $len = strlen( $content );

        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $content[ $i ];
            if ( $escape ) {
                $escape = false;
                continue;
            }
            if ( $ch === '\\' && $in_string ) {
                $escape = true;
                continue;
            }
            if ( $ch === '"' && ! $escape ) {
                $in_string = ! $in_string;
                continue;
            }
            if ( $in_string ) {
                continue;
            }
            if ( $ch === '{' || $ch === '[' ) {
                $stack[] = $ch;
            } elseif ( $ch === '}' ) {
                if ( ! empty( $stack ) && end( $stack ) === '{' ) {
                    array_pop( $stack );
                }
            } elseif ( $ch === ']' ) {
                if ( ! empty( $stack ) && end( $stack ) === '[' ) {
                    array_pop( $stack );
                }
            }
        }

        // 如果在字符串中间截断，先闭合字符串
        $repaired = $content;
        if ( $in_string ) {
            $repaired .= '"';
        }

        // 移除最后一个可能不完整的键值对（如 "tags": "标签1,标...）
        // 查找最后一个完整的值后面是否有未完成的键值对
        $repaired = preg_replace( '/,\s*"[^"]*":\s*"[^"]*$/', '', $repaired );
        $repaired = preg_replace( '/,\s*"[^"]*":\s*\d*\.?\d*$/', '', $repaired );

        // 闭合剩余的括号
        $stack = array_reverse( $stack );
        foreach ( $stack as $bracket ) {
            $repaired .= ( $bracket === '{' ) ? '}' : ']';
        }

        $decoded = json_decode( $repaired, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && ! empty( $decoded['title'] ) ) {
            return $decoded;
        }

        return null;
    }

    /**
     * 调用 AI API（兼容 OpenAI 格式的所有提供商）
     */
    public function call_api( $messages, $max_tokens = 2048, $options = array() ) {
        $body = array(
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => $options['temperature'] ?? 0.85,   // 改写时可覆盖为更高值
            'top_p'       => $options['top_p'] ?? 0.9,
        );

        // 移动云 MoMA 不支持 presence_penalty / frequency_penalty
        if ( $this->provider !== 'moma' ) {
            $body['presence_penalty']  = $options['presence_penalty'] ?? 0.3;
            $body['frequency_penalty'] = $options['frequency_penalty'] ?? 0.2;
        }

        $encoded_body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $encoded_body ) {
            return new WP_Error( 'invalid_request_body', 'AI 请求内容无法编码，请检查提示词中是否含有非法字符。' );
        }

        $args = array(
            'method'  => 'POST',
            'timeout' => 180,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $encoded_body,
        );

        // 使用 wp_remote_post（支持本地AI/Ollama等内网地址）
        // wp_safe_remote_post 会阻断对内网地址的请求，导致本地AI无法使用
        $response = wp_remote_post( $this->api_url, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'API 请求失败：' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err_msg = $body['error']['message'] ?? $body['message'] ?? ( is_string( $body['error'] ?? null ) ? $body['error'] : "HTTP {$code}" );
            return new WP_Error( 'api_http_' . $code, 'API 返回错误：' . $err_msg );
        }

        // 新增：校验返回结构，某些中转/代理会在 200 下返回空 choices 或错误对象
        if ( ! is_array( $body ) ) {
            $raw = wp_remote_retrieve_body( $response );
            yimai_gso_debug_log( '[一麦GSO AI] API 返回非标准格式（HTTP 200）：' . mb_substr( $raw, 0, 500 ) );
            return new WP_Error( 'invalid_api_response', 'API 返回非标准格式，请联系 API 提供商或检查代理配置。' );
        }

        if ( ! empty( $body['error'] ) ) {
            $err_msg = is_array( $body['error'] ) ? ( $body['error']['message'] ?? json_encode( $body['error'] ) ) : (string) $body['error'];
            return new WP_Error( 'api_error', 'API 返回错误：' . $err_msg );
        }

        if ( empty( $body['choices'] ) || ! is_array( $body['choices'] ) ) {
            $raw = wp_remote_retrieve_body( $response );
            yimai_gso_debug_log( '[一麦GSO AI] API 返回缺少 choices（HTTP 200）：' . mb_substr( $raw, 0, 500 ) );
            return new WP_Error( 'empty_api_choices', 'AI 未返回有效生成结果，请检查 API Key、模型名称或余额后重试。' );
        }

        return $body;
    }

    /**
     * 测试 API 连接
     */
    public function test_connection() {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请填写 API Key' );
        }
        if ( empty( $this->api_url ) ) {
            return new WP_Error( 'no_api_url', '请填写 API 接口地址' );
        }
        if ( empty( $this->model ) ) {
            return new WP_Error( 'no_model', '请填写模型名称' );
        }

        $response = $this->call_api( array(
            array( 'role' => 'user', 'content' => '你好，请回复"连接成功"三个字' ),
        ), 50 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return true;
    }

    /**
     * 批量生成文章配图 ALT 文本
     *
     * @param array $article_context 文章上下文
     * @param array $slots           需要生成 ALT 的图片槽位
     * @return array|WP_Error        [ slot => alt ]
     */
    public function generate_image_alt( $article_context = array(), $slots = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        if ( empty( $slots ) || ! is_array( $slots ) ) {
            return array();
        }

        $language = ! empty( $article_context['language'] ) && stripos( (string) $article_context['language'], 'en' ) === 0
            ? 'English'
            : '中文';
        $title    = sanitize_text_field( $article_context['title'] ?? '' );
        $keyword  = sanitize_text_field( $article_context['keyword'] ?? '' );
        $excerpt  = sanitize_textarea_field( $article_context['excerpt'] ?? '' );

        $slot_lines = array();
        foreach ( $slots as $slot ) {
            $slot_lines[] = sprintf(
                '- slot=%d；heading=%s；current_alt=%s；source=%s',
                absint( $slot['slot'] ?? 0 ),
                sanitize_text_field( $slot['heading'] ?? '' ),
                sanitize_text_field( $slot['current_alt'] ?? '' ),
                sanitize_text_field( $slot['source'] ?? '' )
            );
        }

        $sys_prompt = "你是一位擅长网站无障碍与SEO优化的图片编辑。\n" .
            "请为文章中的图片生成适合写入 img alt 属性的文本。\n" .
            "要求：\n" .
            "1. 输出语言必须与文章语言一致；当前文章语言为 {$language}\n" .
            "2. alt 要自然、具体，描述图片与文章段落的关系，不要写'图片''配图''image'这类空泛词\n" .
            "3. 单条尽量简短：中文建议 12-28 字，英文建议 6-12 个词\n" .
            "4. 不要带引号、序号、Markdown、HTML\n" .
            "5. 严格只返回 JSON 数组，格式：[{\"slot\":1,\"alt\":\"...」}]";

        $user_message = "文章标题：{$title}\n" .
            "核心主题：{$keyword}\n" .
            "摘要参考：{$excerpt}\n\n" .
            "请为以下图片槽位生成 ALT：\n" . implode( "\n", $slot_lines );

        $response = $this->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user', 'content' => $user_message ),
        ), 1200 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? '';
        $content = preg_replace( '/^```json\s*/m', '', $content );
        $content = preg_replace( '/^```\s*/m', '', $content );
        $content = trim( $content );

        $items = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $items ) ) {
            if ( preg_match( '/\[[\s\S]*\]/', $content, $match ) ) {
                $items = json_decode( $match[0], true );
            }
        }

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $items ) ) {
            return new WP_Error( 'invalid_alt_json', 'AI 返回的 ALT 结果不是有效 JSON' );
        }

        $alt_map = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $slot = absint( $item['slot'] ?? 0 );
            $alt  = sanitize_text_field( $item['alt'] ?? '' );
            if ( $slot > 0 && '' !== $alt ) {
                $alt_map[ $slot ] = $alt;
            }
        }

        return $alt_map;
    }

    /**
     * 标题角度类型定义
     */
    private static $title_angles = array(
        'tutorial'  => '教程类（手把手教学、步骤指南）',
        'reveal'    => '揭秘类（行业秘密、内幕曝光）',
        'list'      => '盘点类（Top榜单、合集推荐）',
        'pitfall'   => '避坑类（新手误区、常见错误）',
        'trend'     => '趋势类（行业走向、未来预测）',
        'case'      => '案例类（真实故事、成功经验）',
        'knowledge' => '干货类（硬核知识、深度干货）',
        'qa'        => '问答类（提出疑问、给出解答）',
        'compare'   => '对比类（A vs B、优缺点对比）',
        'story'     => '故事类（个人经历、心路历程）',
        'hot'       => '热点类（蹭热点、趁势而为）',
        'mindset'   => '思维类（认知提升、底层逻辑）',
        'myth'      => '反常识类（颠覆认知、打破常规）',
        'data'      => '数据类（数据分析、研究总结）',
        'audience'  => '人群类（特定人群、细分领域）',
    );

    /**
     * 批量生成热门标题（基于行业关键词/主题）
     * @param string $keyword 行业关键词或主题
     * @param int $count 生成标题数量
     * @return array|WP_Error 标题数组
     */
    public function generate_titles( $keyword, $count = 20, $angles = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        $count = max( 5, min( $count, 50 ) ); // 限制5-50个

        // 构建角度指令
        if ( ! empty( $angles ) && is_array( $angles ) ) {
            // 用户选了特定角度 → 只使用这些角度，按数量均衡分配
            $angle_labels = array();
            foreach ( $angles as $angle_key ) {
                if ( isset( self::$title_angles[ $angle_key ] ) ) {
                    $angle_labels[] = self::$title_angles[ $angle_key ];
                }
            }
            $angle_text = implode('、', $angle_labels);
            $angle_instruction = "3. 只从以下角度生成标题，不要超出范围：{$angle_text}\n" .
                                 "4. 标题在各角度之间尽量均匀分配，不要集中在某一种角度\n";
        } else {
            // 用户没选角度 → 使用全部角度，均衡分配
            $angle_text = implode('、', self::$title_angles);
            $angle_instruction = "3. 涵盖以下所有角度，在各角度之间尽量均匀分配，不要集中在某几种：{$angle_text}\n";
        }

        $sys_prompt = "你是一位资深的新媒体内容策划专家，擅长创作兼顾SEO优化和高点击率的标题。\n" .
                      "标题必须同时满足：包含目标关键词（自然融入，不堆砌）、符合搜索引擎友好规范、具有用户吸引力。\n" .
                      "请根据用户提供的行业关键词或主题，生成{$count}个文章标题/提示词。\n\n" .
                      "要求：\n" .
                      "1. 标题要有吸引力，能引起好奇心或提供明确价值\n" .
                      "2. 每个标题就是一个完整的文章主题提示词（15-28字）\n" .
                      $angle_instruction .
                      "5. 标题长度控制在15-28字\n" .
                      "6. 核心关键词只出现1次，禁止为SEO而堆砌关键词\n" .
                      "7. 避免标题党：禁止使用『震惊』『99%的人不知道』等夸张表述（符合百度清风算法）\n" .
                      "8. 标题要准确反映文章内容，禁止题文不符\n" .
                      "9. 严格只返回JSON数组格式，不要返回任何其他文字\n" .
                      "10. 每个元素就是一个字符串，不需要序号\n\n" .
                      "示例格式：\n" .
                      "[\"标题1\", \"标题2\", \"标题3\"]";

        $user_message = "行业关键词/主题：{$keyword}\n请生成{$count}个热门文章标题。";

        // 标题生成增加一次重试，应对 API 偶发空返回
        $max_attempts = 2;
        $last_error   = null;
        $response     = null;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $response = $this->call_api( array(
                array( 'role' => 'system', 'content' => $sys_prompt ),
                array( 'role' => 'user',   'content' => $user_message ),
            ), 2048 );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                if ( $attempt < $max_attempts ) {
                    error_log( '[一麦GSO-标题生成] 第 ' . $attempt . ' 次请求失败，准备重试：' . $response->get_error_message() );
                    sleep( 1 );
                    continue;
                }
                break;
            }

            $content = $response['choices'][0]['message']['content'] ?? '';

            // ★ 调试日志：记录 AI 原始返回（排查标题生成失败的关键数据）
            $log_snippet = mb_substr( $content, 0, 800 );
            // T3：改为受控日志，避免每次调用都无条件写入 800 字符 AI 原始返回
            yimai_gso_debug_log( '[一麦GSO-标题生成] AI原始返回(' . mb_strlen( $content ) . '字): ' . $log_snippet );

            if ( '' === trim( (string) $content ) ) {
                $provider = $this->provider;
                $model    = $this->model;
                error_log( "[一麦GSO-标题生成] 第 {$attempt} 次 AI 返回空内容(provider={$provider}, model={$model})" );
                $last_error = new WP_Error( 'empty_title_response', "AI 返回空内容（{$provider}/{$model}），请检查 API Key 余额、模型可用性或更换模型后重试。" );
                if ( $attempt < $max_attempts ) {
                    sleep( 1 );
                    continue;
                }
                break;
            }

            // 成功拿到非空内容，跳出重试
            $last_error = null;
            break;
        }

        if ( is_wp_error( $last_error ) ) {
            return $last_error;
        }

        if ( ! is_array( $response ) || empty( $response['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'invalid_title_response', '标题生成响应异常，请检查 API 设置。' );
        }

        $content = $response['choices'][0]['message']['content'];

        // 清理 markdown 代码块标记
        $content = preg_replace( '/^```json\s*/m', '', $content );
        $content = preg_replace( '/^```\s*/m', '', $content );
        $content = trim( $content );

        // 解析 JSON 数组
        $titles = json_decode( $content, true );
        $json_error = json_last_error();

        if ( $json_error !== JSON_ERROR_NONE || ! is_array( $titles ) ) {
            error_log( '[一麦GSO-标题生成] JSON解析失败(err=' . $json_error . ')，尝试按行分割' );

            // 尝试按行分割（同时清理 markdown 引号干扰）
            $lines = preg_split( '/[\n\r]+/', $content );
            $titles = array();
            foreach ( $lines as $line ) {
                // 清除行首序号、引号、markdown列表标记等
                $line = preg_replace( '/^[\d]+[\.\、\)]\s*/u', '', trim( $line ) );
                $line = preg_replace( '/^[-*+]\s+/u', '', $line );
                $line = trim( $line, '"\'「」『』""\x{201C}\x{201D}\x{2018}\x{2019} ' );
                if ( ! empty( $line ) && mb_strlen( $line ) >= 3 ) {
                    $titles[] = $line;
                }
            }
        }

        if ( is_array( $titles ) && ! empty( $titles ) && isset( $titles[0] ) && is_array( $titles[0] ) ) {
            // AI 错误地返回了 [{...}, {...}] 对象数组，尝试提取 title/name 字段
            error_log( '[一麦GSO-标题生成] 检测到对象数组，尝试提取 title 字段' );
            $extracted = array();
            foreach ( $titles as $item ) {
                $t = $item['title'] ?? $item['name'] ?? $item['text'] ?? '';
                if ( ! empty( $t ) ) {
                    $extracted[] = $t;
                }
            }
            $titles = $extracted;
        }

        // 过滤空值��去重
        $titles = array_values( array_filter( $titles, function( $t ) {
            return ! empty( $t ) && is_string( $t );
        } ) );
        $titles = array_values( array_unique( $titles ) );

        error_log( '[一麦GSO-标题生成] 最终解析到 ' . count( $titles ) . ' 个标题' );

        return $titles;
    }

    /**
     * 单篇文章生成
     * @param array $params {
     *   @type string $keyword    关键词/主题
     *   @type string $title     自定义标题（可选）
     *   @type string $length    长度：short/medium/long
     *   @type string $type       类型：guide/news/opinion
     *   @type string $style      写作风格
     *   @type bool   $with_image 是否配图
     * }
     * @return array|WP_Error
     */
    public function generate_single_article( $params = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        $keyword = $params['keyword'] ?? '';
        $custom_title = $params['title'] ?? '';
        $length = $params['length'] ?? 'medium';
        $type = $params['type'] ?? 'guide';
        $style = $params['style'] ?? '';
        $with_image = $params['with_image'] ?? true;
        $angle = $params['angle'] ?? ''; // 写作角度

        // 长度映射
        $length_map = array(
            'short'  => 800,
            'medium' => 1500,
            'long'   => 2500,
        );
        $word_count = $length_map[ $length ] ?? 1500;

        // 类型映射（扩展至8种）
        $type_map = array(
            'guide'    => array( 'label' => '教程攻略类文章',  'tip' => '手把手教学，步骤清晰，有实操性' ),
            'news'     => array( 'label' => '资讯新闻类文章',  'tip' => '客观报道，信息新鲜，有新闻价值' ),
            'opinion'  => array( 'label' => '观点评论类文章',  'tip' => '有立场有论证，逻辑清晰，观点鲜明' ),
            'review'   => array( 'label' => '产品评测类文章',  'tip' => '需包含参数对比、优缺点分析、实际体验，建议附对比表' ),
            'case'     => array( 'label' => '案例分析类文章',  'tip' => '需包含背景、问题分析、解决方案、效果数据，有借鉴意义' ),
            'listicle' => array( 'label' => '清单盘点类文章',  'tip' => '每项独立小节含序号标题，开篇总述，结尾总结推荐' ),
            'story'    => array( 'label' => '故事叙事类文章',  'tip' => '有情节线，有场景描写，有情感共鸣，真实自然' ),
            'qa'       => array( 'label' => '问答解惑类文章',  'tip' => '以问答结构展开，每个 H2 是一个常见问题，第一段直接回答' ),
        );
        $type_info  = $type_map[ $type ] ?? $type_map['guide'];
        $type_desc  = $type_info['label'];
        $type_tip   = $type_info['tip'];

        // 角度描述（复用 batch 角度体系，单篇只用1个角度）
        $angle_text = '';
        if ( ! empty( $angle ) && isset( self::$title_angles[ $angle ] ) ) {
            $angle_text = "\n写作角度：采用「" . self::$title_angles[ $angle ] . "」视角来组织内容。\n";
        }

        // 构建提示词
        $title_part = $custom_title ? "文章标题：「{$custom_title}」" : "根据主题「{$keyword}」生成一个吸引人的标题";
        $style_part = $style ? "写作风格：{$style}" : '';
        $user_message = "请用中文写一篇{$type_desc}。\n" .
                        "{$title_part}\n" .
                        "核心关键词：{$keyword}\n" .
                        "类型要求：{$type_tip}\n" .
                        "{$angle_text}" .
                        "SEO要求：\n" .
                        "- 标题含核心关键词1次，15-28字，匹配用户搜索意图\n" .
                        "- 正文覆盖该主题下用户可能关心的3-5个子话题（每个H2对应一个子话题）\n" .
                        "- 内容要有实操方法、数据支撑或真实案例，确保信息增量\n" .
                        "- 适配百度清风算法：标题真实不夸大，正文原创有深度\n" .
                        "约{$word_count}字，内容专业实用，结构清晰。\n" .
                        "【字数与完整性硬要求】目标约{$word_count}字，允许上下浮动 20%（约 " . (int) ( $word_count * 0.8 ) . "–" . (int) ( $word_count * 1.2 ) . " 字）；完整性优先于字数——宁可略微超出，也不要把任何小标题写成只剩提问或半句话；每个 H2/H3 必须写出完整答案；全篇结尾必须有一句总结收束，禁止以提问或未完成的句子结束。\n" .
                        "{$style_part}\n" .
                        "文风要自然真实，像真人写的博客，不要有AI生成痕迹。\n" .
                        "正文请使用HTML格式，包含小标题(h2/h3)、段落(p)、列表(ul/li)、加粗(strong)等元素。";

        // 单篇生成使用独立链路，必须显式接入 GEO 实体和品牌资料上下文。
        if ( class_exists( 'YIMAI_GSO_Knowledge_Center' ) ) {
            $user_message = YIMAI_GSO_Knowledge_Center::augment_prompt( $user_message, $custom_title ?: $keyword );
        }

        // 调用 API（带重试机制，最多2次，与 generate_titles 保持一致）
        $max_attempts = 2;
        $last_error  = null;

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            if ( $attempt > 1 ) {
                usleep( 500000 ); // 重试前等待 500ms
            }

            // 单篇生成系统提示词：优先使用设置中的自定义值，否则使用内置默认
            $custom_system_prompt = YIMAI_GSO_Settings::get( 'single_generate_system_prompt', '' );
            $default_system_prompt = YIMAI_GSO_Settings::get_default_single_generate_system_prompt();

            // 自定义提示词完全覆盖；留空则用内置默认
            $system_content = ! empty( trim( $custom_system_prompt ) )
                ? $custom_system_prompt
                : $default_system_prompt;
            $system_content .= "\n\n【一麦瑜伽品牌事实硬约束】除非用户明确要求竞品对比，否则不得推荐或虚构其他品牌、机构或门店；涉及一麦瑜伽的名称、服务和地址只能使用提示词中的 canonical 实体与资料事实。";

            // 关键修复：原先写死 4096 max_tokens，与目标字数无关。
            // 中文 1500 字 + HTML 标签的 token 开销通常 3000–4500，4096 极易在中途被截断
            // （表现为文章末尾停在某个提问上、问题没有答案）。
            // 改为按目标字数动态计算：每字 6 tokens + 3000 结构开销，下限 4096，上限 16384。
            $single_max_tokens = max( 4096, min( 16384, (int) ( $word_count * 6 ) + 3000 ) );

            $response = $this->call_api( array(
                array(
                'role' => 'system',
                    'content' => $system_content,
                ),
                array( 'role' => 'user', 'content' => $user_message ),
            ), $single_max_tokens );

            if ( ! is_wp_error( $response ) ) {
                $last_error = null; // 成功，清除错误
                break;
            }

            $last_error = $response;
            // 400 通常是模型、参数或请求格式错误，重试不会改变结果。
            if ( strpos( (string) $response->get_error_code(), 'api_http_4' ) === 0 ) {
                break;
            }
        }

        if ( is_wp_error( $last_error ) ) {
            error_log( '[YIMAI GSO] AI调用失败（' . $max_attempts . '次尝试后）: ' . $last_error->get_error_message() );
            return $last_error;
        }

        $content = $response['choices'][0]['message']['content'] ?? '';

        // 空内容检测
        if ( empty( trim( $content ) ) ) {
            error_log( '[YIMAI GSO] AI 返回空内容，参数: keyword=' . $keyword . ', length=' . $length . ', type=' . $type );
        }

        // 解析 JSON - 使用增强的提取方法
        $article = $this->extract_and_decode_json( $content );
        
        // 如果第一次提取失败，尝试清理 markdown 标记后再提取
        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            $content_clean = preg_replace( '/^```json\s*/m', '', $content );
            $content_clean = preg_replace( '/^```\s*/m', '', $content_clean );
            $content_clean = trim( $content_clean );
            $article = $this->extract_and_decode_json( $content_clean );
        }

        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            // 解析失败，从 AI 响应中提取纯文本内容
            $fallback_content = $this->extract_plain_text_from_ai_response( $content );

            // 安全：移除 AI 可能生成在正文中的 <img> 标签
            $safe_content = preg_replace( '/<img\b[^>]*\/?>/i', '', $fallback_content );

            // 防护：兜底文本如果明显是提示词回显，宁可失败也不要把提示词发成文章
            if ( $this->looks_like_prompt_echo( $safe_content ) ) {
                return new WP_Error(
                    'prompt_echo',
                    'AI 未返回有效 JSON 且疑似回显提示词，已拒绝生成。请检查模型是否可用或稍后重试。'
                );
            }

            $article = array(
                'title'          => $custom_title ?: $keyword,
                'excerpt'        => mb_substr( strip_tags( $safe_content ), 0, 100 ),
                'content'        => $safe_content,
                'tags'           => $keyword,
                'keywords'       => $keyword,
                'image_keywords'  => $this->local_keyword_mapping( $keyword ),
            );
        }

        // ★ 自动续写：模型按「约 X 字」的指令常主动收尾，
        //   表现为最后一个小标题只有提问、问题没有答案，或正文停在半句话。
        //   这里检测并续写（最多 2 轮），保证文章完整再返回。
        $article = $this->maybe_continue_content( $article, $word_count, $system_content ?? '', $user_message );

        // 表格补内联样式（预览与发布都有边框）
        if ( ! empty( $article['content'] ) && class_exists( 'YIMAI_GSO_Publisher' ) ) {
            $article['content'] = YIMAI_GSO_Publisher::normalize_table_styles( $article['content'] );
        }

        // 安全：移除 AI 生成的正文中的 <img> 标签（防止外部图片被下载）
        if ( ! empty( $article['content'] ) && is_string( $article['content'] ) ) {
            $article['content'] = preg_replace( '/<img\b[^>]*\/?>/i', '', $article['content'] );
        }

        // 处理配图
        $image_count = 0;
        $first_attach_id = 0;
        if ( $with_image && ! empty( $article['image_keywords'] ) ) {
            require_once YIMAI_GSO_PLUGIN_DIR . 'includes/class-image-handler.php';
            $image_handler = new YIMAI_GSO_Image_Handler();

            // 获取配图数量设置
            $image_count_setting = (int) YIMAI_GSO_Settings::get( 'image_count', 3 );
            $image_keyword = is_array( $article['image_keywords'] )
                ? implode( ',', $article['image_keywords'] )
                : $article['image_keywords'];

            // ★ AI 生图模式：使用 image_prompts 分段生成
            $img_provider = YIMAI_GSO_Settings::get( 'image_provider', 'pexels' );
            $image_prompts = isset( $article['image_prompts'] ) && is_array( $article['image_prompts'] )
                ? $article['image_prompts'] : array();

            if ( 'ai' === $img_provider && ! empty( $image_prompts ) && class_exists( 'YIMAI_GSO_AI_Image_Generator' ) ) {
                // AI 生图模式：生成阶段只画 1 张封面，其余留给发布阶段
                $ai_gen = new YIMAI_GSO_AI_Image_Generator();
                $images = array();
                $section_map = array();
                $pending_prompts = array();

                $gen_count = min( $image_count_setting, count( $image_prompts ) );
                // 生成阶段最多只生成 1 张（封面），避免 AJAX 超时
                $phase_one_count = ( $gen_count > 1 ) ? 1 : $gen_count;

                for ( $i = 0; $i < $gen_count; $i++ ) {
                    $ip = $image_prompts[ $i ];
                    $prompt_text = is_array( $ip ) ? ( $ip['prompt'] ?? '' ) : $ip;
                    $section_name = is_array( $ip ) ? ( $ip['section'] ?? '' ) : '';

                    if ( empty( $prompt_text ) ) {
                        continue;
                    }

                    if ( $i < $phase_one_count ) {
                        // Phase 1: 立即生成（封面图）
                        $gen_result = $ai_gen->generate( $prompt_text, 1, array(
                            'title'   => $article['title'] ?? '',
                            'keyword' => $image_keyword,
                            'section' => $section_name,
                        ) );

                        if ( ! is_wp_error( $gen_result ) && ! empty( $gen_result ) ) {
                            $images[] = $gen_result[0];
                            $section_map[] = $section_name;
                        }
                    } else {
                        // Phase 2: 留给发布阶段
                        $pending_prompts[] = array(
                            'section' => $section_name,
                            'prompt'  => $prompt_text,
                        );
                    }
                }

                // 按章节匹配插入已生成的图片
                if ( ! empty( $images ) ) {
                    $article['content'] = $this->inject_images_by_section(
                        $article['content'], $images, $section_map
                    );
                    $image_count = count( $images );
                }
            } else {
                // 传统图库模式：用关键词搜索图片（上下文供 MT Photos AI 关联度审核）
                $images = $image_handler->fetch_images( $image_keyword, $image_count_setting, array(
                    'title'   => $article['title'] ?? '',
                    'keyword' => $image_keyword,
                    'excerpt' => $article['excerpt'] ?? '',
                ) );

                if ( ! is_wp_error( $images ) && ! empty( $images ) ) {
                    // 在每个 H2 后面逐张插入配图（一张 H2 对应一张图，轮流使用）
                    $img_index = 0;
                    $article['content'] = preg_replace_callback(
                        '/(<\/h2>)/i',
                        function ( $matches ) use ( &$img_index, $images ) {
                            if ( ! isset( $images[ $img_index ] ) ) {
                                return $matches[1];
                            }
                            $img     = $images[ $img_index ];
                            $img_tag = '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?? '' ) . '" /></p>';
                            $img_index++;
                            return $matches[1] . "\n" . $img_tag;
                        },
                        $article['content']
                    );
                    $image_count = count( $images );
                }
            }
        }

        // 计算字数
        $text_only = strip_tags( $article['content'] );
        $word_count = mb_strlen( preg_replace( '/\s+/', '', $text_only ) );

        return array(
            'title'         => $article['title'] ?? '',
            'content'       => $article['content'] ?? '',
            'excerpt'       => $article['excerpt'] ?? '',
            'tags'          => $article['tags'] ?? '',
            'keywords'      => $article['keywords'] ?? '',
            'image_keywords'=> $article['image_keywords'] ?? '',
            'image_prompts' => $article['image_prompts'] ?? array(),
            'pending_image_prompts' => $pending_prompts ?? array(),
            'word_count'    => $word_count,
            'image_count'   => $image_count ?? 0,
        );
    }

    /**
     * 按章节匹配插入图片（AI 生图模式专用）
     *
     * 根据 image_prompts 中的 section 字段，将图片插入到对应 <h2> 之后。
     * 封面图（section="封面"）插入到第一个 <h2> 之前（文章开头）或作为首图。
     *
     * @param string $content     文章 HTML 内容
     * @param array  $images      图片数组 [{url, alt, ...}]
     * @param array  $sections    每张图对应的章节名
     * @return string
     */
    private function inject_images_by_section( $content, $images, $sections ) {
        if ( empty( $images ) ) {
            return $content;
        }

        // 分离封面图和非封面图
        $cover_image = null;
        $section_images = array(); // [section_index => image]
        foreach ( $images as $i => $img ) {
            $section = isset( $sections[ $i ] ) ? $sections[ $i ] : '';
            if ( '封面' === $section || 'cover' === strtolower( $section ) ) {
                $cover_image = $img;
            } else {
                $section_images[ $section ] = $img;
            }
        }

        // 如果只有封面图，插在文章开头（第一个 <h2> 之前）
        if ( $cover_image && empty( $section_images ) ) {
            $img_tag = '<p><img src="' . esc_url( $cover_image['url'] ) . '" alt="' . esc_attr( $cover_image['alt'] ?? '' ) . '" /></p>';
            $content = preg_replace( '/<h2/i', $img_tag . '<h2', $content, 1 );
            return $content;
        }

        // 按章节匹配：遍历每个 <h2>，匹配章节名
        $result = preg_replace_callback(
            '/<h2[^>]*>(.*?)<\/h2>/i',
            function ( $matches ) use ( $section_images, &$cover_image ) {
                $h2_text = trim( wp_strip_all_tags( $matches[1] ) );
                $img_tag = '';

                // 封面图：第一个匹配到的 H2 前插入
                if ( $cover_image ) {
                    $img_tag = '<p><img src="' . esc_url( $cover_image['url'] ) . '" alt="' . esc_attr( $cover_image['alt'] ?? '' ) . '" /></p>';
                    $cover_image = null;
                }

                // 章节匹配：检查是否有对应的图片
                foreach ( $section_images as $section_name => $img ) {
                    if ( $section_name && mb_strpos( $h2_text, $section_name ) !== false ) {
                        $img_tag .= '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?? '' ) . '" /></p>';
                        unset( $section_images[ $section_name ] ); // 用完即删
                        break;
                    }
                }

                return $matches[0] . "\n" . $img_tag;
            },
            $content
        );

        // 如果仍有未匹配的图片，追加到内容末尾
        if ( ! empty( $section_images ) ) {
            foreach ( $section_images as $img ) {
                $result .= "\n" . '<p><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['alt'] ?? '' ) . '" /></p>';
            }
        }

        return $result;
    }

    /**
     * 为指定文章生成 AI 摘要
     *
     * @param int|WP_Post $post  文章 ID 或 WP_Post 对象
     * @return string|WP_Error   成功返回摘要文本，失败返回 WP_Error
     */
    public function generate_excerpt( $post ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        if ( is_numeric( $post ) ) {
            $post = get_post( absint( $post ) );
        }
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'invalid_post', '文章不存在或类型不支持' );
        }

        $title   = $post->post_title;
        $content = $post->post_content;

        // 取正文前 3000 字给 AI（摘要不需要全文）
        $plain_text = wp_strip_all_tags( strip_shortcodes( $content ) );
        $plain_text = trim( preg_replace( '/\s+/', ' ', $plain_text ) );
        $char_count = mb_strlen( $plain_text );
        if ( $char_count > 3000 ) {
            $plain_text = mb_substr( $plain_text, 0, 3000 );
        }

        if ( empty( $plain_text ) ) {
            return new WP_Error( 'empty_content', '文章内容为空，无法生成摘要' );
        }

        // 获取分类和标签作为上下文
        $category_names = array();
        $cats = get_the_category( $post->ID );
        if ( $cats ) {
            foreach ( $cats as $cat ) {
                $category_names[] = $cat->name;
            }
        }
        $tag_names = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

        $sys_prompt = "你是一位资深中文SEO编辑，擅长撰写搜索引擎友好的文章摘要。\n" .
            "请为以下文章生成一段80-150字的SEO摘要。\n" .
            "要求：\n" .
            "1. 概括文章核心内容和价值点\n" .
            "2. 自然包含核心关键词，不堆砌\n" .
            "3. 结尾引导读者阅读全文\n" .
            "4. 语气自然，像真人写的博客摘要\n" .
            "5. 不要出现'本文将''本文旨在'等机械表达\n\n" .
            "请严格返回 JSON，不要返回任何解释或 Markdown：\n" .
            "{\"excerpt\": \"摘要内容\"}";

        $user_message = "文章标题：{$title}\n";
        if ( ! empty( $category_names ) ) {
            $user_message .= "分类：" . implode( '、', $category_names ) . "\n";
        }
        if ( ! empty( $tag_names ) ) {
            $user_message .= "标签：" . implode( '、', $tag_names ) . "\n";
        }
        $user_message .= "\n文章正文（{$char_count}字）：\n{$plain_text}";

        $response = $this->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user',   'content' => $user_message ),
        ), 600 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $result = $response['choices'][0]['message']['content'] ?? '';

        // 清理 markdown 代码块标记
        $result = preg_replace( '/^```json\s*/m', '', $result );
        $result = preg_replace( '/^```\s*/m', '', $result );
        $result = trim( $result );

        $data = json_decode( $result, true );

        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['excerpt'] ) ) {
            // JSON 解析失败，尝试从文本中提取
            if ( ! empty( $result ) && mb_strlen( $result ) >= 20 ) {
                return sanitize_textarea_field( mb_substr( $result, 0, 200 ) );
            }
            return new WP_Error( 'excerpt_parse_error', 'AI 返回的摘要格式异常，请重试' );
        }

        return sanitize_textarea_field( $data['excerpt'] );
    }

    /**
     * AI 内容改写/翻新
     *
     * @param string $source_text  原文内容
     * @param string $source_title 原标题（可选，留空则 AI 自动生成）
     * @param string $strength     改写力度：light / medium / deep
     * @return array|WP_Error      { title, content, excerpt, tags }
     */
    public function rewrite_article( $source_text, $source_title = '', $strength = 'medium', $style = 'default', $keywords = '', $fields = array() ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', '请先在设置中填写 AI API Key' );
        }

        if ( empty( trim( $source_text ) ) ) {
            return new WP_Error( 'empty_source', '原文内容不能为空' );
        }

        // 提取原文中的图片标签，改写后重新插入
        $original_images = array();
        if ( preg_match_all( '/<img\b[^>]*\/?>/i', $source_text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $idx => $match ) {
                $original_images[] = array(
                    'html'  => $match[0],
                    'index' => $idx,
                );
            }
        }

        // 力度指令映射 — 每个力度都强调必须用自己的话重写，禁止复制原句
        $strength_instructions = array(
            'light'  => '【轻度改写】用你自己的话重新表达原文。替换至少30%的词汇为同义词，改变句式（把字句↔被字句、陈述句↔疑问句），调整状语位置。禁止直接复制原文句子。',
            'medium' => '【中度改写】用全新的表达方式重写原文。替换至少60%的词汇，大幅改变句式结构，重新组织段落顺序，合并或拆分句子。原文有5段就改写为7段，有7段就改为5段。禁止出现与原文相同的整句。',
            'deep'   => '【深度改写】完全重写。只保留原文的核心事实和数据，用完全不同的语言、结构、角度和风格来表达。就像不同作者写同一话题一样。80%以上的文字必须与原文不同。',
        );
        $strength_desc = $strength_instructions[ $strength ] ?? $strength_instructions['medium'];

        // 风格预设
        $style_instructions = array(
            'default'     => '保持原文的语气和风格',
            'professional'=> '【专业风格】使用正式、权威的表达方式。多用行业术语、数据支撑、逻辑连接词。语气严谨、客观、可信。',
            'casual'      => '【口语化风格】降低阅读门槛。用短句，像朋友聊天一样自然。少用书面语，多用日常用语。可以适当加入口语化表达如"其实""说白了""举个例子"等。',
            'marketing'   => '【营销风格】增强吸引力。开头要有 hook 钩子，善用问句和感叹句制造紧迫感。强调痛点、给出解决方案、加入行动号召。多用"你"拉近距离。',
            'academic'    => '【学术风格】严谨、逻辑清晰。论点-论据-论证结构完整。引用数据和事实支撑观点。避免情绪化语言，保持中立客观。',
        );
        $style_desc = $style_instructions[ $style ] ?? $style_instructions['default'];

        // 字段选择
        $rewrite_title   = empty( $fields ) || in_array( 'title', $fields, true );
        $rewrite_content = empty( $fields ) || in_array( 'content', $fields, true );
        $rewrite_excerpt = empty( $fields ) || in_array( 'excerpt', $fields, true );
        $rewrite_tags    = empty( $fields ) || in_array( 'tags', $fields, true );

        $title_part = '';
        if ( $rewrite_title ) {
            $title_part = ! empty( $source_title )
                ? "原标题（仅供参考）：{$source_title}"
                : '请根据内容自动生成一个吸引人的标题（15-28字，SEO友好）';
        } else {
            $title_part = ! empty( $source_title )
                ? "原标题：{$source_title}（标题不需要改写，请原样保留）"
                : '标题请留空（不需要改写标题）';
        }

        $field_constraints = '';
        if ( ! $rewrite_title )   $field_constraints .= "\n- **标题**：不需要改写，原标题原样保留";
        if ( ! $rewrite_content ) $field_constraints .= "\n- **正文**：原文内容保持不变，content字段输出原文";
        if ( ! $rewrite_excerpt ) $field_constraints .= "\n- **摘要**：不需要生成，excerpt留空";
        if ( ! $rewrite_tags )    $field_constraints .= "\n- **标签**：不需要生成，tags留空";

        // 关键词注入
        $keyword_constraint = '';
        if ( ! empty( trim( $keywords ) ) ) {
            $keyword_list = array_map( 'trim', explode( ',', $keywords ) );
            $keyword_list = array_filter( $keyword_list );
            if ( ! empty( $keyword_list ) ) {
                $kw_str = implode( '、', $keyword_list );
                $keyword_constraint = "\n\n【关键词要求 — 必须遵守】\n改写后的正文必须自然地包含以下关键词：{$kw_str}\n每个关键词至少出现1次，但不要生硬堆砌，要融入上下文中。";
            }
        }

        // 截取原文前 8000 字给 AI（防止超 token）
        $plain_text = wp_strip_all_tags( $source_text );
        $plain_text = trim( preg_replace( '/\s+/', ' ', $plain_text ) );
        $char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $plain_text ) : strlen( $plain_text );
        if ( $char_count > 8000 ) {
            $plain_text = ( function_exists( 'mb_substr' ) ? mb_substr( $plain_text, 0, 8000 ) : substr( $plain_text, 0, 8000 ) ) . "\n\n【原文较长，已截取前8000字作为改写依据】";
        }

        $sys_prompt = "你是一位资深中文编辑，专攻文章改写。你必须严格遵循以下改写要求，然后用JSON格式输出结果。

【改写要求 — 最重要的部分，优先执行】
{$strength_desc}

【风格要求】
{$style_desc}

1. **不得复制原文句子**：每个句子都必须用自己的话重新写
2. **严禁偷懒**：不能只改几个词就交差，必须从结构层面重新组织
3. **标题全新创作**：不能使用原标题或稍作修改
4. **段落结构打乱**：合并短段、拆分长段，改变段落数量和顺序
5. **去AI味**：禁止使用「至关重要」「毋庸置疑」「综上所述」「在当今」等套话
6. **文风自然**：段落长短错落，读起来像真人写的
{$field_constraints}
{$keyword_constraint}

【输出格式要求】
返回一个合法JSON对象，不要加任何说明文字：
{
  \"title\": \"SEO优化标题（15-28字）\",
  \"content\": \"HTML格式改写正文（h2/h3标题、p段落、ul/li列表、strong加粗）\",
  \"excerpt\": \"SEO摘要（80-150字）\",
  \"tags\": \"标签1,标签2,标签3\"
}";

        $user_message = "{$title_part}\n\n原文内容（{$char_count}字）：\n{$plain_text}";

        // 根据原文长度动态计算 max_tokens：确保输出不会被截断
        // 中文字符约 1.5 tokens/字，HTML 标签额外开销，预留 30% 余量
        $estimated_output_tokens = (int) ( $char_count * 2.0 + 1000 );
        $max_tokens = min( 16384, max( 4096, $estimated_output_tokens ) );

        // 改写模式用更高 temperature + penalty，强制 AI 产出差异内容
        $rewrite_options = array(
            'light'  => array( 'temperature' => 0.95, 'presence_penalty' => 0.5, 'frequency_penalty' => 0.4 ),
            'medium' => array( 'temperature' => 1.05, 'presence_penalty' => 0.7, 'frequency_penalty' => 0.5 ),
            'deep'   => array( 'temperature' => 1.15, 'presence_penalty' => 0.9, 'frequency_penalty' => 0.6 ),
        );
        $api_options = $rewrite_options[ $strength ] ?? $rewrite_options['medium'];

        $response = $this->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user',   'content' => $user_message ),
        ), $max_tokens, $api_options );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content = $response['choices'][0]['message']['content'] ?? '';

        // 检查 AI 返回是否因 max_tokens 不足被截断
        $finish_reason = $response['choices'][0]['finish_reason'] ?? '';
        $is_truncated  = ( 'length' === $finish_reason );

        // 解析 JSON - 使用增强的提取方法
        $article = $this->extract_and_decode_json( $content );
        
        // 如果第一次提取失败，尝试清理 markdown 标记后再提取
        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            $content_clean = preg_replace( '/^```json\s*/m', '', $content );
            $content_clean = preg_replace( '/^```\s*/m', '', $content_clean );
            $content_clean = trim( $content_clean );
            $article = $this->extract_and_decode_json( $content_clean );
        }

        // 如果被截断，尝试修复被截断的 JSON
        if ( $is_truncated ) {
            $repaired = $this->try_repair_truncated_json( $content_clean ?? $content );
            if ( is_array( $repaired ) && ! empty( $repaired['title'] ) ) {
                $article = $repaired;
            } else {
                // 修复失败，返回错误提示用户
                return new WP_Error( 'truncated', 'AI 返回内容过长被截断，请缩短原文长度或降低改写力度后重试' );
            }
        }

        if ( ! is_array( $article ) || empty( $article['title'] ) ) {
            // JSON 解析失败，从 AI 响应中提取纯文本内容
            $fallback_content = $this->extract_plain_text_from_ai_response( $content );

            // 如果 fallback 也为空，返回明确错误
            if ( empty( trim( $fallback_content ) ) ) {
                return new WP_Error( 'empty_content', 'AI 返回的正文内容为空，请更换改写力度或检查 API 设置后重试' );
            }

            // 防护：兜底文本如果明显是提示词回显，宁可失败也不要把提示词发成文章
            if ( $this->looks_like_prompt_echo( $fallback_content ) ) {
                return new WP_Error(
                    'prompt_echo',
                    'AI 未返回有效 JSON 且疑似回显提示词，已拒绝改写。请检查模型是否可用或稍后重试。'
                );
            }

            // 构造 article 数组，走后续完整处理流程（图片重插、HTML格式化、控制字符清理、相似度检查）
            $article = array(
                'title'   => $source_title ?: 'AI改写结果',
                'content' => $fallback_content,
                'excerpt' => ( function_exists( 'mb_substr' ) ? mb_substr( wp_strip_all_tags( $fallback_content ), 0, 100 ) : substr( wp_strip_all_tags( $fallback_content ), 0, 100 ) ),
                'tags'    => '',
            );
        }

        // 修复转义
        foreach ( array( 'content', 'excerpt', 'title' ) as $text_field ) {
            if ( ! empty( $article[ $text_field ] ) && is_string( $article[ $text_field ] ) ) {
                $article[ $text_field ] = str_replace(
                    array( '\\n', '\\t', '\\r' ),
                    array( "\n",  "\t",  "\r"  ),
                    $article[ $text_field ]
                );
            }
        }

        // ⚡ 修复：JSON解析成功但 content 为空 → 尝试 fallback 提取
        $parsed_content = trim( $article['content'] ?? '' );
        $has_content    = ! empty( $parsed_content ) && $parsed_content !== 'HTML格式的改写后正文';
        if ( ! $has_content ) {
            $fallback_content = $this->extract_plain_text_from_ai_response( $content );
            $safe_content     = preg_replace( '/<img\b[^>]*\/?>/i', '', $fallback_content );
            if ( ! empty( trim( $safe_content ) ) ) {
                $parsed_content = $safe_content;
            }
        }

        // 如果原文有图片，将图片重新插入到改写后的内容中
        if ( ! empty( $original_images ) && ! empty( $parsed_content ) ) {
            $parsed_content = $this->reinsert_original_images( $parsed_content, $original_images );
        }

        // 清理 content 末尾可能混入的 JSON 残留数据（与 generate_article 保持一致）
        $parsed_content = $this->clean_json_residue_from_content( $parsed_content );

        // 仅对标题/摘要/标签清理控制字符，正文保留原始 HTML 结构不变
        $title   = isset( $article['title'] ) ? $this->sanitize_control_chars( $article['title'] ) : '';
        $excerpt = isset( $article['excerpt'] ) ? $this->sanitize_control_chars( $article['excerpt'] ) : '';
        $tags    = isset( $article['tags'] ) ? $this->sanitize_control_chars( $article['tags'] ) : '';

        // 检查改写有效性：如果改写后的内容和原文高度相似（>85%），视为改写失败
        $source_plain = wp_strip_all_tags( $source_text );
        $result_plain = wp_strip_all_tags( $parsed_content );
        $similarity = $this->calculate_similarity( $source_plain, $result_plain );

        if ( $similarity > 85 ) {
            return new WP_Error( 'rewrite_failed', 'AI 返回的内容与原文过于相似（相似度 ' . round( $similarity ) . '%），请更换改写力度或检查 API 设置后重试' );
        }

        return array(
            'title'   => $title,
            'content' => $parsed_content,
            'excerpt' => $excerpt,
            'tags'    => $tags,
        );
    }

    /**
     * 将原文图片重新插入到改写后的内容中
     *
     * @param string $content        改写后的内容
     * @param array  $original_images 原文图片数组 [{html, index}]
     * @return string 插入图片后的内容
     */
    private function reinsert_original_images( $content, $original_images ) {
        if ( empty( $original_images ) ) {
            return $content;
        }

        $image_count = count( $original_images );

        // 如果只有1张图片，插入到正文最前面
        if ( $image_count === 1 ) {
            return $original_images[0]['html'] . "\n\n" . $content;
        }

        // 多张图片时，均匀分布到内容中
        // 先按段落分割
        $paragraphs = preg_split( '/(<\/p>|<\/h[1-6]>|<\/li>|<\/div>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( count( $paragraphs ) < 2 ) {
            // 内容太短，所有图片放最前面
            $images_html = implode( "\n", wp_list_pluck( $original_images, 'html' ) );
            return $images_html . "\n\n" . $content;
        }

        // 计算每隔多少个段落插入一张图片
        $total_blocks = count( $paragraphs );
        $interval     = max( 1, floor( $total_blocks / ( $image_count + 1 ) ) );

        $result       = array();
        $image_index  = 0;
        $block_count  = 0;

        foreach ( $paragraphs as $i => $block ) {
            $result[] = $block;
            $block_count++;

            // 在合适的段落后面插入图片
            if ( $block_count % $interval === 0 && $image_index < $image_count ) {
                // 确保是在结束标签后面插入
                if ( preg_match( '/<\/(p|h[1-6]|li|div)>/i', $block ) ) {
                    $result[] = "\n" . $original_images[ $image_index ]['html'];
                    $image_index++;
                }
            }
        }

        // 如果还有剩余图片没插入，放到末尾
        while ( $image_index < $image_count ) {
            $result[] = "\n" . $original_images[ $image_index ]['html'];
            $image_index++;
        }

        return implode( '', $result );
    }
}
