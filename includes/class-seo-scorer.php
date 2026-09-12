<?php
/**
 * SEO 综合评分检测器
 * 对已发布文章进行 SEO 健康度评分（0-100分）
 * 检测维度：标题合规、关键词密度、Meta描述、内容深度、图片优化、内链、外链、可读性、标题结构
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_SEO_Scorer {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 评分维度配置（权重总和 = 100）
     */
    private static function get_dimensions() {
        return array(
            'title_compliance' => array(
                'label'  => '标题合规',
                'weight' => 13,
                'icon'   => 'dashicons-edit',
                'engine' => 'seo',
            ),
            'keyword_density'  => array(
                'label'  => '关键词密度',
                'weight' => 13,
                'icon'   => 'dashicons-search',
                'engine' => 'seo',
            ),
            'meta_description' => array(
                'label'  => 'Meta描述',
                'weight' => 10,
                'icon'   => 'dashicons-editor-paragraph',
                'engine' => 'seo',
            ),
            'content_depth'    => array(
                'label'  => '内容深度',
                'weight' => 15,
                'icon'   => 'dashicons-media-text',
                'engine' => 'seo',
            ),
            'image_optimization' => array(
                'label'  => '图片优化',
                'weight' => 10,
                'icon'   => 'dashicons-format-image',
                'engine' => 'seo',
            ),
            'internal_links'   => array(
                'label'  => '内链建设',
                'weight' => 10,
                'icon'   => 'dashicons-admin-links',
                'engine' => 'seo',
            ),
            'heading_structure' => array(
                'label'  => '标题结构',
                'weight' => 7,
                'icon'   => 'dashicons-heading',
                'engine' => 'seo',
            ),
            'readability'      => array(
                'label'  => '可读性',
                'weight' => 10,
                'icon'   => 'dashicons-visibility',
                'engine' => 'seo',
            ),
            'external_links'   => array(
                'label'  => '出站链接',
                'weight' => 5,
                'icon'   => 'dashicons-external',
                'engine' => 'seo',
            ),
            'content_extractability' => array(
                'label'  => '内容可提取性',
                'weight' => 8,
                'icon'   => 'dashicons-grid-view',
                'engine' => 'geo',
            ),
            'content_freshness' => array(
                'label'  => '内容新鲜度',
                'weight' => 7,
                'icon'   => 'dashicons-clock',
                'engine' => 'geo',
            ),
        );
    }

    private function __construct() {}

    /**
     * 对单篇文章进行完整 SEO 评分
     * @param int $post_id 文章 ID
     * @return array 评分结果
     */
    public function analyze( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'error' => '文章不存在' );
        }

        $title   = $post->post_title;
        $content = $post->post_content;
        $plain   = wp_strip_all_tags( $content );
        $excerpt = get_post_meta( $post_id, 'yimai_gso_excerpt', true );
        if ( empty( $excerpt ) ) {
            $excerpt = $post->post_excerpt;
        }
        if ( empty( $excerpt ) ) {
            // 尝试从 Yoast / Rank Math / 子比 / AIOSEO 读取
            $yoast_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
            $rm_desc    = get_post_meta( $post_id, 'rank_math_description', true );
            $zib_desc   = get_post_meta( $post_id, 'description', true );
            $excerpt    = $yoast_desc ?: $rm_desc ?: $zib_desc;
        }

        $keywords = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( ! empty( $keywords ) && is_string( $keywords ) ) {
            $keyword_arr = explode( ',', $keywords );
        } elseif ( is_array( $keywords ) ) {
            $keyword_arr = $keywords;
        } else {
            $keyword_arr = array();
        }
        // 清理空值和首尾空白
        $keyword_arr = array_values( array_filter( array_map( 'trim', $keyword_arr ) ) );

        // 智能提取核心关键词：从标题中提取最可能的短关键词（2-8字）
        $primary_keyword = '';
        if ( ! empty( $keyword_arr ) ) {
            $primary_keyword = $keyword_arr[0];
        }
        if ( empty( $primary_keyword ) ) {
            $primary_keyword = $this->extract_focus_keyword( $title, $plain );
        }

        // 如果 yimai_gso_keywords 为空但有 Yoast/Rank Math focus keyword，也补充进来
        if ( empty( $keyword_arr ) ) {
            $yoast_kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
            if ( ! empty( $yoast_kw ) ) {
                $keyword_arr = array_map( 'trim', explode( ',', $yoast_kw ) );
            } else {
                $rm_kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
                if ( ! empty( $rm_kw ) ) {
                    $keyword_arr = array_map( 'trim', explode( ',', $rm_kw ) );
                }
            }
            $keyword_arr = array_values( array_filter( $keyword_arr ) );
        }

        // 逐维度评分
        $scores = array();
        $scores['title_compliance']    = $this->score_title( $title, $primary_keyword );
        $scores['keyword_density']     = $this->score_keyword_density( $plain, $primary_keyword );
        $scores['meta_description']    = $this->score_meta_description( $excerpt, $primary_keyword, $title );
        $scores['content_depth']       = $this->score_content_depth( $content, $plain );
        $scores['image_optimization']  = $this->score_images( $content );
        $scores['internal_links']      = $this->score_internal_links( $content );
        $scores['heading_structure']   = $this->score_heading_structure( $content, $primary_keyword );
        $scores['readability']         = $this->score_readability( $plain );
        $scores['external_links']      = $this->score_external_links( $content );
        $scores['content_extractability'] = $this->score_extractability( $post_id );
        $scores['content_freshness']    = $this->score_freshness( $post_id );

        // 计算加权总分
        $dims    = self::get_dimensions();
        $total   = 0;
        $weights = 0;
        foreach ( $scores as $key => $s ) {
            $w = isset( $dims[ $key ] ) ? $dims[ $key ]['weight'] : 0;
            $total   += $s['score'] * $w;
            $weights += $w;
        }
        $overall = $weights > 0 ? round( $total / $weights ) : 0;

        // 等级判定
        $grade = $this->get_grade( $overall );

        // GEO 答案前置检测（独立于SEO评分，作为双引擎指标）
        $geo_answer_first = $this->detect_answer_first( $post_id, $content );

        // 收集 GEO 维度数据
        $geo_extractability = $scores['content_extractability'];
        $geo_freshness      = $scores['content_freshness'];

        $result = array(
            'post_id'    => $post_id,
            'post_title' => $title,
            'overall'    => $overall,
            'grade'      => $grade,
            'scores'     => $scores,
            'geo'        => array(
                'answer_first'  => $geo_answer_first,
                'extractability'=> $geo_extractability,
                'freshness'     => $geo_freshness,
            ),
            'keywords'   => $keyword_arr,
            'primary_kw' => $primary_keyword,
            'word_count' => mb_strlen( $plain ),
            'analyzed_at'=> current_time( 'mysql' ),
        );

        // 存入 post_meta
        update_post_meta( $post_id, 'yimai_gso_seo_score', $result );

        return $result;
    }

    /**
     * 标题合规评分
     */
    private function score_title( $title, $keyword ) {
        $score = 0;
        $tips  = array();
        $len   = mb_strlen( $title );

        // 长度检查（15-30字最佳）
        if ( $len >= 15 && $len <= 30 ) {
            $score += 35;
        } elseif ( $len >= 10 && $len <= 40 ) {
            $score += 20;
            $tips[] = '标题长度' . $len . '字，建议控制在15-30字之间';
        } else {
            $tips[] = '标题长度' . $len . '字，严重偏离15-30字最佳区间';
        }

        // 关键词包含
        if ( ! empty( $keyword ) && mb_stripos( $title, $keyword ) !== false ) {
            $score += 30;
        } else {
            $tips[] = '标题中未包含核心关键词，建议自然融入';
        }

        // 标题党检测（过多感叹号、问号）
        $punctuation_count = substr_count( $title, '！' ) + substr_count( $title, '!' ) + substr_count( $title, '？') + substr_count( $title, '?' );
        if ( $punctuation_count <= 2 ) {
            $score += 15;
        } else {
            $score += 5;
            $tips[] = '标题感叹号/问号过多（' . $punctuation_count . '个），有标题党嫌疑，可能触发百度清风算法';
        }

        // 标题重复字检测
        if ( $len > 0 && $this->has_repetitive_chars( $title ) ) {
            $tips[] = '标题存在重复用词，可能被识别为标题堆砌';
        } else {
            $score += 10;
        }

        // 有数字加分
        if ( preg_match( '/\d/', $title ) ) {
            $score += 10;
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 关键词密度评分
     */
    private function score_keyword_density( $plain_text, $keyword ) {
        $score = 0;
        $tips  = array();

        if ( empty( $keyword ) || empty( $plain_text ) ) {
            return $this->build_dim_result( 30, array( '未检测到关键词，请先设置核心关键词' ) );
        }

        $text_len = mb_strlen( $plain_text );
        if ( $text_len < 100 ) {
            return $this->build_dim_result( 20, array( '正文过短（' . $text_len . '字），无法准确评估关键词密度' ) );
        }

        // 计算关键词出现次数（精确+忽略大小写）
        $kw_len  = mb_strlen( $keyword );
        $count   = mb_substr_count( $plain_text, $keyword );
        $count_ci = mb_substr_count( mb_strtolower( $plain_text ), mb_strtolower( $keyword ) );
        $count   = max( $count, $count_ci );

        // 模糊匹配：如果精确匹配为0，用关键词的核心部分（3-6字子串）在正文中查找
        // 如果超过40%的子串在正文中出现，估算一个近似次数
        $fuzzy = false;
        if ( $count === 0 && $kw_len >= 4 ) {
            $parts    = array();
            $kw_lower = mb_strtolower( $keyword );
            for ( $n = 3; $n <= min( 6, $kw_len ); $n++ ) {
                for ( $i = 0; $i <= $kw_len - $n; $i++ ) {
                    $parts[] = mb_substr( $kw_lower, $i, $n );
                }
            }
            $plain_lower  = mb_strtolower( $plain_text );
            $match_parts  = 0;
            foreach ( $parts as $part ) {
                if ( mb_stripos( $plain_lower, $part ) !== false ) {
                    $match_parts++;
                }
            }
            if ( count( $parts ) > 0 && $match_parts / count( $parts ) >= 0.4 ) {
                // 模糊匹配成功——取关键词中最长的3字子串来估算次数
                $best_sub  = '';
                $best_freq = 0;
                for ( $n = 3; $n <= min( 6, $kw_len ); $n++ ) {
                    for ( $i = 0; $i <= $kw_len - $n; $i++ ) {
                        $sub = mb_substr( $kw_lower, $i, $n );
                        $freq = mb_substr_count( $plain_lower, $sub );
                        if ( $freq > $best_freq ) {
                            $best_freq = $freq;
                            $best_sub  = $sub;
                        }
                    }
                }
                if ( $best_freq >= 2 ) {
                    // 用匹配子串的字符数来估算密度（保守估计）
                    $sub_len = mb_strlen( $best_sub );
                    $count   = min( $best_freq, $kw_len > 9 ? ceil( $best_freq * 0.6 ) : $best_freq );
                    $fuzzy   = true;
                }
            }
        }

        // 密度 = (关键词字符数 * 出现次数) / 正文总字数 * 100
        $density = round( ( $kw_len * $count ) / $text_len * 100, 2 );

        // 最佳区间 1.5% - 3.5%（长尾关键词场景可适当放宽）
        if ( $density >= 1.5 && $density <= 3.5 ) {
            $score += 50;
            $tips[] = '关键词密度 ' . $density . '%，在最佳区间（1.5%-3.5%）内' . ( $fuzzy ? '（模糊匹配）' : '' );
        } elseif ( $density >= 1.0 && $density < 1.5 ) {
            $score += 35;
            $tips[] = '关键词密度 ' . $density . '%，偏低，建议在正文中自然增加1-2次关键词' . ( $fuzzy ? '（模糊匹配）' : '' );
        } elseif ( $density > 3.5 && $density <= 6.0 ) {
            $score += 35;
            $tips[] = '关键词密度 ' . $density . '%，略高，但仍在可接受范围内' . ( $fuzzy ? '（模糊匹配）' : '' );
        } elseif ( $density > 6.0 && $density <= 10.0 ) {
            $score += 20;
            $tips[] = '关键词密度 ' . $density . '%，偏高，建议适当精简以提升可读性' . ( $fuzzy ? '（模糊匹配）' : '' );
        } elseif ( $density > 10.0 ) {
            $score += 10;
            $tips[] = '关键词密度 ' . $density . '%，过高！搜索引擎可能判定为关键词堆砌，建议减少';
        } else {
            $score += 15;
            $tips[] = '关键词密度 ' . $density . '%，严重不足，需要在正文中自然增加2-3次';
        }

        // 关键词出现次数
        if ( $count >= 3 ) {
            $score += 25;
        } elseif ( $count >= 1 ) {
            $score += 15;
            $tips[] = '关键词仅出现' . $count . '次' . ( $fuzzy ? '（模糊匹配）' : '' ) . '，建议至少出现3次以上';
        } else {
            $tips[] = '关键词未在正文中出现';
        }

        // 前100字是否包含关键词（同样做模糊匹配）
        $first_100 = mb_substr( $plain_text, 0, 100 );
        $first_match = mb_stripos( $first_100, $keyword ) !== false;
        // 模糊：关键词的前半部分是否在开头出现
        if ( ! $first_match && $kw_len >= 4 ) {
            $first_half = mb_substr( $keyword, 0, max( 2, (int) ( $kw_len * 0.6 ) ) );
            $first_match = mb_stripos( $first_100, $first_half ) !== false;
        }
        if ( $first_match ) {
            $score += 25;
        } else {
            $tips[] = '前100字未包含核心关键词，建议在开头段落自然融入';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * Meta 描述评分
     */
    private function score_meta_description( $excerpt, $keyword, $title = '' ) {
        $score = 0;
        $tips  = array();

        if ( empty( $excerpt ) ) {
            return $this->build_dim_result( 10, array( '未设置 Meta 描述，搜索引擎会自动截取正文，建议设置100-170字的SEO描述' ) );
        }

        $len = mb_strlen( wp_strip_all_tags( $excerpt ) );

        // 长度评分（80-180为理想区间，更宽松）
        if ( $len >= 80 && $len <= 180 ) {
            $score += 40;
        } elseif ( $len >= 50 && $len <= 220 ) {
            $score += 25;
            $tips[] = '描述长度' . $len . '字，建议控制在80-180字';
        } else {
            $score += 10;
            $tips[] = '描述长度' . $len . '字，' . ( $len < 50 ? '过短' : '过长' ) . '，建议控制在80-180字';
        }

        // 包含关键词（模糊匹配：检查关键词的每个2字以上子串是否在excerpt中存在）
        $has_keyword = false;
        if ( ! empty( $keyword ) ) {
            // 先精确匹配
            if ( mb_stripos( $excerpt, $keyword ) !== false ) {
                $has_keyword = true;
            } else {
                // 模糊匹配：关键词拆分后至少有一半以上的词块在excerpt中出现
                $kw_parts = array();
                $kw_lower = mb_strtolower( $keyword );
                $kw_len   = mb_strlen( $kw_lower );
                for ( $n = 2; $n <= min( 4, $kw_len ); $n++ ) {
                    for ( $i = 0; $i <= $kw_len - $n; $i++ ) {
                        $kw_parts[] = mb_substr( $kw_lower, $i, $n );
                    }
                }
                $excerpt_lower = mb_strtolower( $excerpt );
                $match_count  = 0;
                foreach ( $kw_parts as $part ) {
                    if ( mb_stripos( $excerpt_lower, $part ) !== false ) {
                        $match_count++;
                    }
                }
                if ( count( $kw_parts ) > 0 && $match_count / count( $kw_parts ) >= 0.5 ) {
                    $has_keyword = true;
                }
            }
        }

        if ( $has_keyword ) {
            $score += 35;
        } else {
            $tips[] = '描述中未包含核心关键词';
        }

        // 描述与标题不重复（对比去除噪音后）
        if ( ! empty( $title ) ) {
            $clean_excerpt = preg_replace( '/\s+/', '', mb_strtolower( wp_strip_all_tags( $excerpt ) ) );
            $clean_title   = preg_replace( '/\s+/', '', mb_strtolower( $title ) );
            // 如果摘要内容不完全等于标题（至少多出20字以上）
            if ( mb_strlen( $clean_excerpt ) - mb_strlen( $clean_title ) > 20 || mb_stripos( $clean_excerpt, $clean_title ) === false ) {
                $score += 25;
            } else {
                $tips[] = '描述不应简单重复标题内容';
            }
        } else {
            $score += 25; // 无法比对，不扣分
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 内容深度评分
     */
    private function score_content_depth( $html, $plain ) {
        $score = 0;
        $tips  = array();

        $word_count = mb_strlen( $plain );

        // 字数评分（必应推荐1200-1800字）
        if ( $word_count >= 1200 && $word_count <= 3000 ) {
            $score += 40;
            $tips[] = '正文字数' . $word_count . '字，在最佳区间';
        } elseif ( $word_count >= 800 && $word_count < 1200 ) {
            $score += 25;
            $tips[] = '正文字数' . $word_count . '字，建议扩充到1200字以上以提升内容深度';
        } elseif ( $word_count >= 400 && $word_count < 800 ) {
            $score += 15;
            $tips[] = '正文字数仅' . $word_count . '字，内容偏薄，搜索引擎偏好深度内容';
        } else {
            $score += 5;
            $tips[] = '正文字数仅' . $word_count . '字，严重不足，建议至少1200字';
        }

        // H2 数量
        $h2_count = preg_match_all( '/<h2[^>]*>/i', $html, $m );
        if ( $h2_count >= 3 && $h2_count <= 6 ) {
            $score += 30;
        } elseif ( $h2_count >= 2 ) {
            $score += 20;
            $tips[] = 'H2标题有' . $h2_count . '个，建议3-6个以增强内容结构';
        } elseif ( $h2_count >= 1 ) {
            $score += 10;
            $tips[] = '仅有1个H2标题，建议增加更多小节标题';
        } else {
            $tips[] = '未使用H2标题，内容缺乏结构化分节';
        }

        // 段落数
        $p_count = preg_match_all( '/<p[^>]*>.*?<\/p>/is', $html, $m );
        if ( $p_count >= 5 ) {
            $score += 15;
        } elseif ( $p_count >= 3 ) {
            $score += 10;
            $tips[] = '段落偏少（' . $p_count . '段），建议增加内容段落';
        } else {
            $tips[] = '段落过少（' . $p_count . '段），内容可读性差';
        }

        // 有列表加分
        $list_count = preg_match_all( '/<(ul|ol)[^>]*>/i', $html, $m );
        if ( $list_count >= 1 ) {
            $score += 15;
        } else {
            $tips[] = '建议使用有序/无序列表来组织要点信息';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 图片优化评分
     */
    private function score_images( $html ) {
        $score = 0;
        $tips  = array();

        preg_match_all( '/<img[^>]+>/i', $html, $imgs );
        $img_count = count( $imgs[0] );

        if ( $img_count === 0 ) {
            return $this->build_dim_result( 20, array( '文章无配图，建议添加1-3张相关图片提升用户体验' ) );
        }

        // 检查 ALT 标签
        $alt_ok = 0;
        $alt_empty = 0;
        foreach ( $imgs[0] as $img ) {
            if ( preg_match( '/alt=["\']([^"\']*)["\']?/i', $img, $m ) ) {
                if ( ! empty( trim( $m[1] ) ) ) {
                    $alt_ok++;
                } else {
                    $alt_empty++;
                }
            } else {
                $alt_empty++;
            }
        }

        $alt_rate = $img_count > 0 ? round( $alt_ok / $img_count * 100 ) : 0;

        if ( $alt_rate === 100 ) {
            $score += 50;
            $tips[] = '所有图片均有ALT标签';
        } elseif ( $alt_rate >= 50 ) {
            $score += 30;
            $tips[] = 'ALT覆盖率' . $alt_rate . '%，还有' . $alt_empty . '张图片缺少ALT';
        } else {
            $score += 10;
            $tips[] = '仅' . $alt_rate . '%的图片有ALT标签，大部分图片缺少描述';
        }

        // 图片数量
        if ( $img_count >= 1 && $img_count <= 5 ) {
            $score += 30;
        } elseif ( $img_count > 5 ) {
            $score += 25;
            $tips[] = '图片数量较多（' . $img_count . '张），注意不要影响页面加载速度';
        }

        // ALT 标签长度
        $short_alt = 0;
        foreach ( $imgs[0] as $img ) {
            if ( preg_match( '/alt=["\']([^"\']*)["\']?/i', $img, $m ) ) {
                if ( mb_strlen( $m[1] ) < 5 && mb_strlen( $m[1] ) > 0 ) {
                    $short_alt++;
                }
            }
        }
        if ( $short_alt > 0 ) {
            $tips[] = $short_alt . '张图片的ALT描述过短，建议使用5-15字的描述性文字';
        } else {
            $score += 20;
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 内链评分
     */
    private function score_internal_links( $html ) {
        $score = 0;
        $tips  = array();

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $links );
        $site_url = home_url();
        $internal = 0;
        $external = 0;

        foreach ( $links[1] as $href ) {
            if ( strpos( $href, $site_url ) === 0 || strpos( $href, '/' ) === 0 ) {
                $internal++;
            } elseif ( strpos( $href, 'http' ) === 0 ) {
                $external++;
            }
        }

        if ( $internal >= 2 && $internal <= 5 ) {
            $score += 60;
            $tips[] = '内链数量' . $internal . '个，合理';
        } elseif ( $internal === 1 ) {
            $score += 40;
            $tips[] = '仅有1个内链，建议增加到2-5个';
        } elseif ( $internal === 0 ) {
            $score += 10;
            $tips[] = '无内链！建议添加2-5个指向站内相关文章的内链';
        } else {
            $score += 50;
            $tips[] = '内链数量' . $internal . '个，较多，确保相关性';
        }

        // 锚文本检查
        $good_anchor = 0;
        foreach ( $links[0] as $link ) {
            $anchor = wp_strip_all_tags( $link );
            if ( mb_strlen( $anchor ) >= 4 && ! preg_match( '/^(点击|这里|详情|链接|查看)/u', $anchor ) ) {
                $good_anchor++;
            }
        }
        if ( $internal > 0 && $good_anchor >= $internal ) {
            $score += 40;
        } elseif ( $good_anchor < $internal ) {
            $score += 20;
            $tips[] = '部分内链锚文本不够描述性，建议使用包含关键词的文字作为锚文本';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 标题结构评分（H1/H2/H3）
     */
    private function score_heading_structure( $html, $keyword ) {
        $score = 0;
        $tips  = array();

        // 检查 H1（在正文中不应有H1，H1是页面标题）
        $h1_count = preg_match_all( '/<h1[^>]*>/i', $html, $m );
        if ( $h1_count === 0 ) {
            $score += 25;
            $tips[] = '正文中无H1标签（页面标题自动为H1），结构正确';
        } else {
            $tips[] = '正文中使用了' . $h1_count . '个H1标签，建议正文仅用H2/H3';
        }

        // H2 数量
        $h2_count = preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/isu', $html, $h2_matches );
        if ( $h2_count >= 3 && $h2_count <= 6 ) {
            $score += 35;
        } elseif ( $h2_count >= 2 ) {
            $score += 25;
            $tips[] = 'H2标题' . $h2_count . '个，建议增加到3-6个';
        } elseif ( $h2_count === 1 ) {
            $score += 15;
            $tips[] = '仅有1个H2标题，内容层次不够丰富';
        } else {
            $tips[] = '未使用H2标题，内容缺少结构化分节';
        }

        // H2 中是否包含关键词变体
        $kw_in_h2 = 0;
        if ( ! empty( $keyword ) && ! empty( $h2_matches[1] ) ) {
            foreach ( $h2_matches[1] as $h2_text ) {
                if ( mb_stripos( wp_strip_all_tags( $h2_text ), $keyword ) !== false ) {
                    $kw_in_h2++;
                }
            }
        }
        if ( $kw_in_h2 >= 1 ) {
            $score += 20;
            $tips[] = $kw_in_h2 . '个H2标题包含核心关键词，有利于SEO';
        } elseif ( ! empty( $keyword ) ) {
            $tips[] = 'H2标题中未发现核心关键词或其变体';
        }

        // H3 数量
        $h3_count = preg_match_all( '/<h3[^>]*>/i', $html, $m );
        if ( $h3_count >= 2 ) {
            $score += 20;
        } elseif ( $h3_count >= 1 ) {
            $score += 10;
            $tips[] = 'H3子标题偏少，适当使用H3可增强内容层次';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 可读性评分
     */
    private function score_readability( $plain_text ) {
        $score = 0;
        $tips  = array();

        $len = mb_strlen( $plain_text );
        if ( $len < 50 ) {
            return $this->build_dim_result( 20, array( '正文过短，无法评估可读性' ) );
        }

        // 1. 段落长度（超长段落检测，得分 0-35）
        $paragraphs = preg_split( '/[\n]{2,}/', $plain_text );
        $paragraphs = array_filter( array_map( 'trim', $paragraphs ), function( $p ) { return mb_strlen( $p ) > 10; } );
        $long_paras  = array_filter( $paragraphs, function( $p ) { return mb_strlen( $p ) > 300; } );
        $long_count  = count( $long_paras );
        if ( $long_count === 0 ) {
            $score += 35;
        } elseif ( $long_count <= 2 ) {
            $score += 20;
            $tips[] = "有 {$long_count} 个超长段落（>300字），建议拆分以提高可读性";
        } else {
            $score += 8;
            $tips[] = "有 {$long_count} 个超长段落（>300字），AI搜索偏好短段落";
        }

        // 2. 句子复杂度（按。！？分段，得分 0-25）
        $sentences = preg_split( '/[。！？\n]+/u', $plain_text );
        $sentences = array_filter( array_map( 'trim', $sentences ) );
        $avg_sentence = count( $sentences ) > 0 ? round( array_sum( array_map( 'mb_strlen', $sentences ) ) / count( $sentences ) ) : 0;
        if ( $avg_sentence >= 15 && $avg_sentence <= 50 ) {
            $score += 25;
        } elseif ( $avg_sentence > 50 && $avg_sentence <= 80 ) {
            $score += 15;
            $tips[] = "平均句长{$avg_sentence}字，句子偏长，建议拆分";
        } elseif ( $avg_sentence > 80 ) {
            $score += 5;
            $tips[] = "平均句长{$avg_sentence}字，句子过长严重影响可读性和AI提取";
        } else {
            $score += 18;
        }

        // 3. 中文过渡词检测（得分 0-15）
        $transitions = array( '因此', '然而', '首先', '其次', '另外', '同时', '总之', '综上', '此外', '不仅如此',
                              '相比之下', '换句话说', '也就是说', '具体来说', '实际上', '值得注意的是',
                              '所以', '因为', '但是', '而且', '或者', '不过', '然后', '最后', '比如' );
        $found = 0;
        foreach ( $transitions as $t ) {
            if ( mb_strpos( $plain_text, $t ) !== false ) $found++;
        }
        if ( $found >= 5 ) {
            $score += 15;
        } elseif ( $found >= 3 ) {
            $score += 10;
        } else {
            $score += 5;
            $tips[] = "过渡词使用不足（仅{$found}个），文章的「步骤感」和逻辑引导感较弱";
        }

        // 4. 被动标记检测（得分 0-10）
        $passive_markers = array( '被', '给', '让', '由', '遭到', '受到' );
        $passive_count = 0;
        foreach ( $passive_markers as $m ) {
            $passive_count += mb_substr_count( $plain_text, $m );
        }
        if ( $passive_count <= 2 ) {
            $score += 10;
        } elseif ( $passive_count <= 5 ) {
            $score += 6;
            $tips[] = "被动句式偏多（{$passive_count}次），建议改用主动语态以提升阅读流畅度";
        } else {
            $score += 3;
            $tips[] = "被动句式过多（{$passive_count}次），主动语态更有利于AI理解和用户阅读";
        }

        // 5. 文本多样性（得分 0-15）
        $unique_chars = count( array_unique( preg_split( '//u', $plain_text, -1, PREG_SPLIT_NO_EMPTY ) ) );
        $diversity = $len > 0 ? round( $unique_chars / $len * 100 ) : 0;
        if ( $diversity >= 22 ) {
            $score += 15;
        } elseif ( $diversity >= 16 ) {
            $score += 10;
        } else {
            $score += 5;
            $tips[] = "用词多样性偏低（{$diversity}%），避免过多重复词汇";
        }

        $result = $this->build_dim_result( $score, $tips );

        // 追加段落拆分建议
        $result = $this->add_paragraph_split_suggestions( $plain_text, $result );

        return $result;
    }

    /**
     * 出站链接评分
     */
    private function score_external_links( $html ) {
        $score = 0;
        $tips  = array();

        $site_url = home_url();
        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $links );

        $external = 0;
        foreach ( $links[1] as $href ) {
            if ( strpos( $href, 'http' ) === 0 && strpos( $href, $site_url ) !== 0 ) {
                $external++;
            }
        }

        if ( $external >= 1 && $external <= 3 ) {
            $score += 60;
            $tips[] = '出站链接' . $external . '个，合理引用权威来源有利于SEO';
        } elseif ( $external === 0 ) {
            $score += 30;
            $tips[] = '无出站链接，适当引用权威来源可提升内容可信度';
        } else {
            $score += 40;
            $tips[] = '出站链接' . $external . '个，偏多，注意控制外链数量';
        }

        // 检查 nofollow
        $nofollow_count = preg_match_all( '/<a[^>]+rel=["\'][^"\']*nofollow[^"\']*["\'][^>]*>/i', $html, $m );
        if ( $external > 0 && $nofollow_count < $external ) {
            $tips[] = '建议对外部链接添加 rel="nofollow" 属性';
        }
        if ( $external > 0 && $nofollow_count > 0 ) {
            $score += 40;
        } else {
            $score += 20;
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 内容可提取性评分（GEO 维度）
     * 调用 YIMAI_GSO_Content_Extractability 引擎
     */
    private function score_extractability( $post_id ) {
        $score = 0;
        $tips  = array();

        if ( class_exists( 'YIMAI_GSO_Content_Extractability' ) ) {
            $extractor = YIMAI_GSO_Content_Extractability::get_instance();
            $data      = $extractor->get_score( $post_id );
            $raw_score = isset( $data['score'] ) ? (int) $data['score'] : 0;

            // 映射 0-100 到本维度分数
            $score = $raw_score;

            if ( $raw_score >= 80 ) {
                $tips[] = '内容可提取性优秀（' . $raw_score . '分），AI 可独立提取大部分段落';
            } elseif ( $raw_score >= 60 ) {
                $tips[] = '内容可提取性良好（' . $raw_score . '分），部分段落可能需要优化';
            } elseif ( $raw_score > 0 ) {
                $tips[] = '内容可提取性不佳（' . $raw_score . '分），建议减少依赖上下文的段落';
            }

            // 附加段落级建议
            if ( ! empty( $data['tips'] ) ) {
                $tips = array_merge( $tips, array_slice( $data['tips'], 0, 2 ) );
            }
        } else {
            $score = 50; // 类不存在时默认中等
            $tips[] = '可提取性模块未加载';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 内容新鲜度评分（GEO 维度）
     * 调用 YIMAI_GSO_Content_Freshness 引擎
     */
    private function score_freshness( $post_id ) {
        $score = 0;
        $tips  = array();

        if ( class_exists( 'YIMAI_GSO_Content_Freshness' ) ) {
            $freshness = YIMAI_GSO_Content_Freshness::get_instance();
            $data      = $freshness->get_freshness( $post_id );
            $raw_score = isset( $data['score'] ) ? (int) $data['score'] : 3;
            $days      = isset( $data['days_since_update'] ) ? (int) $data['days_since_update'] : 0;
            $status    = isset( $data['status'] ) ? $data['status'] : 'unknown';

            // 映射原始 0-7 分 → 0-100 分
            $score_map = array( 7 => 100, 6 => 85, 5 => 70, 4 => 55, 3 => 45, 2 => 30, 1 => 15, 0 => 5 );
            $score = isset( $score_map[ $raw_score ] ) ? $score_map[ $raw_score ] : 45;

            if ( 'fresh' === $status ) {
                $tips[] = '内容新鲜（' . $days . '天内更新），AI 偏好最新信息';
            } elseif ( 'stale' === $status || 'old' === $status ) {
                $tips[] = sprintf( '内容可能过时（%d天未更新），建议刷新以提升排名', $days );
            }
        } else {
            $score = 50;
            $tips[] = '新鲜度模块未加载';
        }

        return $this->build_dim_result( $score, $tips );
    }

    /**
     * 段落拆分建议（增强可读性分析）
     * 对可读性评分结果追加智能拆分建议
     *
     * @param string $plain_text 纯文本内容
     * @param array  $result     score_readability() 返回的结果
     * @return array 增强后的结果，含 split_suggestions
     */
    public function add_paragraph_split_suggestions( $plain_text, $result ) {
        $suggestions = array();

        // 按双换行分割段落
        $paragraphs = preg_split( '/\n{2,}/', $plain_text );
        $paragraphs = array_filter( $paragraphs, function( $p ) {
            return mb_strlen( trim( $p ) ) > 10;
        } );
        $paragraphs = array_values( $paragraphs );

        foreach ( $paragraphs as $idx => $para ) {
            $len = mb_strlen( $para );
            if ( $len <= 150 ) continue;

            // 找到段落中间位置最近的句子边界（。！？）
            $mid   = (int) ( $len / 2 );
            $before = mb_substr( $para, 0, $mid );
            $after  = mb_substr( $para, $mid );

            // 在中间位置前后各搜索 50 个字符找句子边界
            $search_before = mb_substr( $before, max( 0, mb_strlen( $before ) - 50 ) );
            $search_after  = mb_substr( $after, 0, min( 50, mb_strlen( $after ) ) );
            $combined      = $search_before . $search_after;

            $best_pos = 0;
            $best_dist = PHP_INT_MAX;

            if ( preg_match_all( '/[。！？]/u', $combined, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $match ) {
                    $pos_in_combined = $match[1];
                    $pos_in_para = mb_strlen( $search_before ) + $pos_in_combined;
                    $dist = abs( $pos_in_para - $mid );
                    if ( $dist < $best_dist ) {
                        $best_dist = $dist;
                        $best_pos  = $pos_in_para;
                    }
                }
            }

            if ( $best_pos > 0 && $best_pos < $len - 20 ) {
                $split_1 = mb_strlen( mb_substr( $para, 0, $best_pos + 1 ) );
                $split_2 = $len - $split_1;

                // 找到拆分位置的上下文
                $context_start = max( 0, $best_pos - 15 );
                $context = mb_substr( $para, $context_start, 35 );

                $suggestions[] = sprintf(
                    '第%d段过长（%d字），建议在「%s」处拆分为两段（各%d/%d字）',
                    $idx + 1, $len, $context, $split_1, $split_2
                );
            } else {
                $suggestions[] = sprintf(
                    '第%d段过长（%d字），建议拆分但未找到合适的句子边界',
                    $idx + 1, $len
                );
            }
        }

        $result['split_suggestions'] = array_slice( $suggestions, 0, 3 );
        return $result;
    }
    private function build_dim_result( $score, $tips ) {
        $score = max( 0, min( 100, $score ) );
        return array(
            'score' => $score,
            'level' => $this->get_dim_level( $score ),
            'tips'  => $tips,
        );
    }

    /**
     * 维度等级
     */
    private function get_dim_level( $score ) {
        if ( $score >= 80 ) return 'good';
        if ( $score >= 60 ) return 'ok';
        if ( $score >= 40 ) return 'warn';
        return 'bad';
    }

    /**
     * 总分等级
     */
    private function get_grade( $score ) {
        if ( $score >= 85 ) return array( 'label' => '优秀', 'color' => '#22c55e', 'letter' => 'A' );
        if ( $score >= 70 ) return array( 'label' => '良好', 'color' => '#3b82f6', 'letter' => 'B' );
        if ( $score >= 55 ) return array( 'label' => '中等', 'color' => '#f59e0b', 'letter' => 'C' );
        if ( $score >= 40 ) return array( 'label' => '较差', 'color' => '#f97316', 'letter' => 'D' );
        return array( 'label' => '很差', 'color' => '#ef4444', 'letter' => 'F' );
    }

    /**
     * GEO 答案前置检测 (Answer-First Architecture)
     * 评估文章是否符合 AI 大模型爬虫偏好的"答案前置"结构
     * 
     * 评分维度：
     * 1. 首段是否包含直接答案/结论（权重40%）
     * 2. 是否使用问答结构标记（权重30%）
     * 3. 首段信息密度（权重20%）
     * 4. 关键信息位置（权重10%）
     * 
     * @return array { score: 0-100, is_answer_first: bool, tips: array }
     */
    private function detect_answer_first( $post_id, $content = null ) {
        if ( null === $content ) {
            $content = get_post_field( 'post_content', $post_id );
        }
        $plain = wp_strip_all_tags( $content );
        $score = 0;
        $tips  = array();

        // 1. 首段是否包含答案/结论（取前300字符判断）
        $first_para = mb_substr( $plain, 0, 300 );
        $has_answer_keywords = preg_match(
            '/(?:答案是|总结|核心|关键|重点|建议|推荐|结论|值得注意的是|首先|第一步|可以直接|只需|按照|根据|原理|方法|解决方案)/u',
            $first_para
        );
        if ( $has_answer_keywords ) {
            $score += 40;
        } else {
            $tips[] = '【GEO】首段未检测到直接答案或结论，AI爬虫偏好答案前置结构。建议在文章开头用1-2句给出核心答案';
        }

        // 2. 问答结构检测（H2/H3 QA标记 + 问号段落）
        $qa_count = preg_match_all(
            '/<(?:h[23]|p)[^>]*>(?:[^<]*[\?？]|[^<]*(?:什么是|如何|怎么|怎样|为什么|能不能|是否|FAQ|Q[：:]|A[：:]|问[：:]|答[：:]))[^<]*<\/(?:h[23]|p)>/iu',
            $content
        );
        if ( $qa_count >= 3 ) {
            $score += 30;
        } elseif ( $qa_count >= 1 ) {
            $score += 15;
            $tips[] = '【GEO】检测到少量问答结构，建议增加2组以上清晰的问答段落以提高AI可提取性';
        } else {
            $tips[] = '【GEO】未检测到问答结构。使用H2设问+H3解答的格式可大幅提升AI引擎对你的内容的引用概率';
        }

        // 3. 首段信息密度（判断前200字是否有实质性信息，而非引入语）
        $first_200 = mb_substr( $plain, 0, 200 );
        $filler_words = array( '随着', '近年来', '当今', '众所周知', '我们大家', '日常生活', '人们越来越' );
        $has_filler = false;
        foreach ( $filler_words as $fw ) {
            if ( mb_strpos( $first_200, $fw ) !== false ) {
                $has_filler = true;
                break;
            }
        }
        if ( ! $has_filler && mb_strlen( $first_200 ) >= 80 ) {
            $score += 20;
        } else {
            $tips[] = '【GEO】首段信息密度偏低，建议去掉"随着/近年来/当今"等模板化引入语，直接切入主题';
        }

        // 4. 关键信息位置：在正文前20%位置是否有数据/方法论/结论
        $total_len    = max( mb_strlen( $plain ), 1 );
        $first_quarter = mb_substr( $plain, 0, max( 1, (int) ( $total_len * 0.2 ) ) );
        $has_key_info = preg_match(
            '/\d+(?:%|万|亿|元|年|月|日|个|[a-zA-Z])|(?:第一步|方法|核心|关键|原理|流程|架构|策略|标准)/u',
            $first_quarter
        );
        if ( $has_key_info ) {
            $score += 10;
        } else {
            $tips[] = '【GEO】正文前20%未检测到关键数据或方法论关键词，AI爬虫对信息位置敏感，建议将核心信息前置';
        }

        $score = min( 100, $score );

        return array(
            'score'          => $score,
            'is_answer_first'=> $score >= 60,
            'level'          => $score >= 80 ? 'good' : ( $score >= 60 ? 'ok' : ( $score >= 40 ? 'warn' : 'bad' ) ),
            'tips'           => $tips,
        );
    }

    /**
     * 重复用词检测
     */
    private function has_repetitive_chars( $title ) {
        $chars = preg_split( '//u', $title, -1, PREG_SPLIT_NO_EMPTY );
        $counts = array();
        foreach ( $chars as $c ) {
            if ( mb_strlen( $c ) > 0 && ! in_array( $c, array( ' ', '的', '了', '是', '在' ), true ) ) {
                $counts[ $c ] = ( $counts[ $c ] ?? 0 ) + 1;
            }
        }
        foreach ( $counts as $count ) {
            if ( $count >= 3 && mb_strlen( $title ) < 10 * $count ) {
                return true;
            }
        }
        return false;
    }

    /**
     * AJAX: 单篇分析
     */
    public static function ajax_analyze_post() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( '缺少文章ID' );
        }

        $scorer = self::get_instance();
        $result = $scorer->analyze( $post_id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 批量扫描（返回评分概览列表）
     */
    public static function ajax_batch_scan() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        global $wpdb;
        $page     = absint( $_POST['page'] ?? 1 );
        $per_page = absint( $_POST['per_page'] ?? 20 );
        $category = absint( $_POST['category'] ?? 0 );
        $status   = sanitize_text_field( $_POST['status'] ?? 'publish' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );

        $offset = ( $page - 1 ) * $per_page;

        $where = YIMAI_GSO_Post_Type_Helper::sql_in_clause( 'p.post_type' ) . " AND p.post_status != 'trash'";
        if ( $status && $status !== 'any' ) {
            $where .= $wpdb->prepare( " AND p.post_status = %s", $status );
        }
        if ( $category ) {
            $where .= $wpdb->prepare(
                " AND p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)",
                $category
            );
        }
        if ( ! empty( $search ) ) {
            $where .= $wpdb->prepare( " AND p.post_title LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}"
        );

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_date,
                        (SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = 'yimai_gso_seo_score' LIMIT 1) AS score_meta,
                        (SELECT pm2.meta_value FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = 'yimai_gso_geo_score' LIMIT 1) AS geo_score_meta
                 FROM {$wpdb->posts} p WHERE {$where}
                 ORDER BY p.post_date DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ),
            ARRAY_A
        );

        $items = array();
        foreach ( $posts as $p ) {
            $score_data = maybe_unserialize( $p['score_meta'] );
            if ( is_string( $score_data ) ) {
                $decoded = json_decode( $score_data, true );
                if ( $decoded ) {
                    $score_data = $decoded;
                }
            }

            // 解析 GEO 评分缓存
            $geo_data = maybe_unserialize( $p['geo_score_meta'] );
            if ( is_string( $geo_data ) ) {
                $geo_decoded = json_decode( $geo_data, true );
                if ( $geo_decoded ) {
                    $geo_data = $geo_decoded;
                }
            }
            $geo_overall = ( is_array( $geo_data ) && isset( $geo_data['overall'] ) ) ? $geo_data['overall'] : null;
            $geo_grade   = ( is_array( $geo_data ) && isset( $geo_data['grade'] ) ) ? $geo_data['grade'] : null;

            $items[] = array(
                'post_id'      => $p['ID'],
                'post_title'   => $p['post_title'],
                'post_date'    => $p['post_date'],
                'has_score'    => ! empty( $score_data ),
                'overall'      => is_array( $score_data ) ? ( $score_data['overall'] ?? null ) : null,
                'grade'        => is_array( $score_data ) ? ( $score_data['grade'] ?? null ) : null,
                'analyzed_at'  => is_array( $score_data ) ? ( $score_data['analyzed_at'] ?? '' ) : '',
                'geo_overall'  => $geo_overall,
                'geo_grade'    => $geo_grade,
            );
        }

        wp_send_json_success( array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil( $total / $per_page ),
        ) );
    }

    /**
     * AJAX: 批量执行双擎评分（SEO + GEO）
     */
    public static function ajax_batch_analyze() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_ids = isset( $_POST['post_ids'] ) ? (array) $_POST['post_ids'] : array();
        if ( empty( $post_ids ) ) {
            wp_send_json_error( '未选择文章' );
        }

        $scorer  = self::get_instance();
        $results = array( 'success' => 0, 'failed' => 0, 'geo_success' => 0, 'geo_skipped' => 0 );

        foreach ( $post_ids as $id ) {
            $id = absint( $id );
            if ( ! $id ) {
                continue;
            }
            // SEO 评分
            $r = $scorer->analyze( $id );
            if ( ! isset( $r['error'] ) ) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            // GEO 评分（轻量聚合，自带 24h 缓存）
            if ( class_exists( 'YIMAI_GSO_GEO_Scorer' ) ) {
                try {
                    $geo = YIMAI_GSO_GEO_Scorer::get_instance()->compute( $id );
                    $results['geo_success']++;
                } catch ( \Throwable $e ) {
                    error_log( '[一麦GSO] Batch GEO compute failed for post ' . $id . ': ' . $e->getMessage() );
                    $results['geo_skipped']++;
                }
            } else {
                $results['geo_skipped']++;
            }
        }

        wp_send_json_success( $results );
    }

    /**
     * 从标题中智能提取核心关键词（2-8字的短词）
     * 用于无 yimai_gso_keywords 时的 fallback
     *
     * 策略：在正文前500字中查找出现频率最高的2-8字短词，且该短词也在标题中出现
     */
    private function extract_focus_keyword( $title, $plain_text ) {
        $title_lower = mb_strtolower( $title );
        $sample      = mb_strtolower( mb_substr( $plain_text, 0, 2000 ) );

        // 过滤标题中的常见噪音词
        $noise_words = array( '的', '了', '在', '是', '和', '与', '到', '了', '有', '也', '就', '都', '会', '能', '怎么', '如何', '什么', '为什么', '哪些', '多少', '推荐', '排行', '指南', '教程', '大全', '详解', '解析', '测评', '评测', '对比', '介绍', '哪个', '好不好', '值得', '最新', '最好', '最全', '十大', '五个', '五个' );

        // 去掉标题中的数字、括号内容、分隔符
        $clean_title = preg_replace( '/[0-9]+/', '', $title );
        $clean_title = preg_replace( '/[（）()【】\[\]《》「」\-\|·_：:、，,。.！!？?]/u', ' ', $clean_title );
        $clean_title = preg_replace( '/\s+/', ' ', $clean_title );
        $clean_title = trim( $clean_title );

        // 从标题中提取2-8字的候选词（中文词以2-4字为主，英文词以2-3词为主）
        $candidates = array();
        // 中文分词近似：取2-4字窗口
        $len = mb_strlen( $clean_title );
        for ( $n = 2; $n <= 4 && $n <= $len; $n++ ) {
            for ( $i = 0; $i <= $len - $n; $i++ ) {
                $seg = mb_substr( $clean_title, $i, $n );
                $seg = trim( $seg );
                if ( mb_strlen( $seg ) === $n && ! in_array( $seg, $noise_words, true ) ) {
                    $candidates[ $seg ] = true;
                }
            }
        }

        // 英文词：按空格取连续字母
        if ( preg_match_all( '/[a-zA-Z][a-zA-Z\s]{2,25}[a-zA-Z]|[a-zA-Z]{2,}/u', $clean_title, $matches ) ) {
            foreach ( $matches[0] as $word ) {
                $word = trim( $word );
                if ( mb_strlen( $word ) >= 2 && mb_strlen( $word ) <= 20 ) {
                    $candidates[ $word ] = true;
                }
            }
        }

        if ( empty( $candidates ) ) {
            return $title; // fallback 到原标题
        }

        // 在正文前2000字中统计每个候选词的出现次数
        $best_word  = '';
        $best_count = 0;
        foreach ( array_keys( $candidates ) as $word ) {
            $count = mb_substr_count( $sample, mb_strtolower( $word ) );
            if ( $count > $best_count || ( $count === $best_count && mb_strlen( $word ) < mb_strlen( $best_word ) ) ) {
                $best_count = $count;
                $best_word  = $word;
            }
        }

        // 如果最好的词在正文中出现>=2次，就用它；否则还是返回原标题
        if ( $best_count >= 2 && ! empty( $best_word ) ) {
            return $best_word;
        }

        return $title;
    }
}
