<?php
/**
 * H2/H3 标题结构智能分析器
 * 检测文章标题层级合理性，提供 AI 优化建议
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Heading_Analyzer {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * 分析文章标题结构
     * @param int $post_id 文章ID
     * @return array 分析结果
     */
    public function analyze( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array( 'error' => '文章不存在' );
        }

        $content = $post->post_content;
        $title   = $post->post_title;

        // 获取关键词
        $keywords = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( ! empty( $keywords ) && is_string( $keywords ) ) {
            $kw_arr = explode( ',', $keywords );
        } elseif ( is_array( $keywords ) ) {
            $kw_arr = $keywords;
        } else {
            $kw_arr = array();
        }
        $primary_keyword = trim( $kw_arr[0] ?? '' );

        // 提取所有标题
        $headings = $this->extract_headings( $content );

        // 分析各维度
        $h1_check     = $this->check_h1( $headings );
        $h2_structure = $this->check_h2_structure( $headings );
        $h3_structure = $this->check_h3_structure( $headings );
        $kw_coverage  = $this->check_keyword_coverage( $headings, $primary_keyword, $kw_arr );
        $distribution = $this->check_distribution( $content, $headings );
        $suggestions  = $this->generate_suggestions( $headings, $primary_keyword, $title );

        // 总体评分
        $score = $this->calculate_heading_score( $h1_check, $h2_structure, $h3_structure, $kw_coverage, $distribution );

        $result = array(
            'post_id'       => $post_id,
            'post_title'    => $title,
            'score'         => $score,
            'headings'      => $headings,
            'h1_check'      => $h1_check,
            'h2_structure'  => $h2_structure,
            'h3_structure'  => $h3_structure,
            'kw_coverage'   => $kw_coverage,
            'distribution'  => $distribution,
            'suggestions'   => $suggestions,
            'analyzed_at'   => current_time( 'mysql' ),
        );

        // 存入 post_meta
        update_post_meta( $post_id, 'yimai_gso_heading_analysis', $result );

        return $result;
    }

    /**
     * 提取所有标题标签
     */
    private function extract_headings( $html ) {
        $headings = array();

        if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/isu', $html, $matches, PREG_SET_ORDER ) ) {
            $position = 0;
            foreach ( $matches as $m ) {
                $position++;
                $text = wp_strip_all_tags( $m[2] );
                $plain = trim( preg_replace( '/\s+/', ' ', $text ) );
                if ( empty( $plain ) ) continue;

                $headings[] = array(
                    'level'    => (int) $m[1],
                    'text'     => $plain,
                    'position' => $position,
                    'has_keyword' => false, // 后面填充
                );
            }
        }

        return $headings;
    }

    /**
     * H1 检查
     */
    private function check_h1( $headings ) {
        $h1_list = array_filter( $headings, function( $h ) { return $h['level'] === 1; } );
        $count = count( $h1_list );

        if ( $count === 0 ) {
            return array(
                'status'  => 'good',
                'score'   => 100,
                'message' => '正文中无H1标签，页面标题自动为H1，结构正确',
                'count'   => 0,
            );
        }

        return array(
            'status'  => 'bad',
            'score'   => 20,
            'message' => '正文中检测到' . $count . '个H1标签，页面标题已自动为H1，正文应使用H2/H3',
            'count'   => $count,
        );
    }

    /**
     * H2 结构检查
     */
    private function check_h2_structure( $headings ) {
        $h2_list = array_filter( $headings, function( $h ) { return $h['level'] === 2; } );
        $count = count( $h2_list );
        $texts = array_map( function( $h ) { return $h['text']; }, $h2_list );

        // 检查是否有重复标题
        $unique_texts = array_unique( $texts );
        $duplicates = $count - count( $unique_texts );

        $score = 0;
        $messages = array();

        if ( $count >= 3 && $count <= 7 ) {
            $score += 50;
            $messages[] = 'H2标题数量' . $count . '个，结构合理';
        } elseif ( $count >= 2 ) {
            $score += 30;
            $messages[] = 'H2标题' . $count . '个，建议增加到3-7个以增强内容层次';
        } elseif ( $count === 1 ) {
            $score += 15;
            $messages[] = '仅有1个H2标题，建议增加更多章节';
        } else {
            $score += 0;
            $messages[] = '未使用H2标题，内容缺少结构化分节，严重影响SEO';
        }

        if ( $duplicates > 0 ) {
            $score -= 10;
            $messages[] = '有' . $duplicates . '个重复的H2标题，搜索引擎可能认为是重复内容';
        } else {
            $score += 20;
        }

        // H2 长度检查
        $too_short = 0;
        $too_long = 0;
        foreach ( $texts as $t ) {
            $len = mb_strlen( $t );
            if ( $len < 4 ) $too_short++;
            if ( $len > 30 ) $too_long++;
        }
        if ( $too_short > 0 ) {
            $messages[] = $too_short . '个H2标题过短（少于4字），标题应有实际意义';
        }
        if ( $too_long > 0 ) {
            $messages[] = $too_long . '个H2标题过长（超过30字），建议精简';
        }
        if ( $too_short === 0 && $too_long === 0 && $count > 0 ) {
            $score += 30;
        }

        $score = max( 0, min( 100, $score ) );

        return array(
            'status'   => $score >= 60 ? 'good' : ( $score >= 40 ? 'warn' : 'bad' ),
            'score'    => $score,
            'messages' => $messages,
            'count'    => $count,
            'texts'    => $texts,
        );
    }

    /**
     * H3 结构检查
     */
    private function check_h3_structure( $headings ) {
        $h3_list = array_filter( $headings, function( $h ) { return $h['level'] === 3; } );
        $count = count( $h3_list );

        $score = 0;
        $messages = array();

        if ( $count >= 2 ) {
            $score += 60;
            $messages[] = '使用了' . $count . '个H3子标题，层次分明';
        } elseif ( $count === 1 ) {
            $score += 30;
            $messages[] = '仅有1个H3标题，建议适当使用H3来细分内容';
        } else {
            $score += 10;
            $messages[] = '未使用H3标题，适当使用H3可增强内容层次';
        }

        // 检查 H3 是否紧跟在 H2 之后（层级合理性）
        $orphan_h3 = 0;
        $prev_level = 0;
        foreach ( $headings as $h ) {
            if ( $h['level'] === 3 && $prev_level > 3 ) {
                $orphan_h3++;
            }
            $prev_level = $h['level'];
        }

        // 检查是否有 H4+（通常不需要）
        $h4_plus = array_filter( $headings, function( $h ) { return $h['level'] >= 4; } );
        if ( count( $h4_plus ) > 0 ) {
            $messages[] = '使用了H4及以下层级标题（' . count( $h4_plus ) . '个），建议最多使用H3';
            $score -= 10;
        }

        if ( $orphan_h3 === 0 ) {
            $score += 40;
        }

        $score = max( 0, min( 100, $score ) );

        return array(
            'status'   => $score >= 60 ? 'good' : ( $score >= 40 ? 'warn' : 'bad' ),
            'score'    => $score,
            'messages' => $messages,
            'count'    => $count,
        );
    }

    /**
     * 关键词覆盖率检查
     */
    private function check_keyword_coverage( &$headings, $primary, $all_kw ) {
        $score = 0;
        $messages = array();
        $covered = array();

        if ( empty( $primary ) ) {
            return array(
                'status'   => 'warn',
                'score'    => 30,
                'messages' => array( '未检测到核心关键词，跳过关键词覆盖检查' ),
                'covered'  => array(),
                'rate'     => 0,
            );
        }

        $total_h2 = 0;
        $covered_h2 = 0;

        foreach ( $headings as &$h ) {
            if ( $h['level'] === 2 ) {
                $total_h2++;
                $found = false;
                // 检查主关键词
                if ( mb_stripos( $h['text'], $primary ) !== false ) {
                    $found = true;
                    $covered[] = $primary;
                }
                // 检查长尾关键词
                foreach ( $all_kw as $kw ) {
                    $kw = trim( $kw );
                    if ( ! empty( $kw ) && mb_stripos( $h['text'], $kw ) !== false && ! in_array( $kw, $covered ) ) {
                        $found = true;
                        $covered[] = $kw;
                    }
                }
                $h['has_keyword'] = $found;
                if ( $found ) $covered_h2++;
            }
        }
        unset( $h );

        $rate = $total_h2 > 0 ? round( $covered_h2 / $total_h2 * 100 ) : 0;

        if ( $rate >= 60 ) {
            $score = 80;
            $messages[] = '关键词覆盖率 ' . $rate . '%，H2标题中融入了核心关键词和长尾变体';
        } elseif ( $rate >= 30 ) {
            $score = 55;
            $messages[] = '关键词覆盖率 ' . $rate . '%，部分H2标题缺少关键词融入';
        } elseif ( $rate > 0 ) {
            $score = 35;
            $messages[] = '关键词覆盖率仅 ' . $rate . '%，建议在更多H2标题中自然融入关键词';
        } else {
            $score = 15;
            $messages[] = 'H2标题中均未包含关键词，严重影响关键词排名';
        }

        // 第一个H2是否包含关键词
        $first_h2 = null;
        foreach ( $headings as $h ) {
            if ( $h['level'] === 2 ) { $first_h2 = $h; break; }
        }
        if ( $first_h2 && mb_stripos( $first_h2['text'], $primary ) !== false ) {
            $score += 20;
        } elseif ( $first_h2 ) {
            $messages[] = '第一个H2标题未包含核心关键词，建议在首节标题中融入';
        }

        return array(
            'status'   => $score >= 60 ? 'good' : ( $score >= 40 ? 'warn' : 'bad' ),
            'score'    => $score,
            'messages' => $messages,
            'covered'  => array_unique( $covered ),
            'rate'     => $rate,
        );
    }

    /**
     * 标题分布均匀性检查
     */
    private function check_distribution( $content, $headings ) {
        $score = 0;
        $messages = array();

        if ( count( $headings ) < 2 ) {
            return array(
                'status'   => 'warn',
                'score'    => 30,
                'messages' => array( '标题数量不足，无法评估分布' ),
            );
        }

        $plain = wp_strip_all_tags( $content );
        $total_len = mb_strlen( $plain );

        if ( $total_len < 200 ) {
            return array(
                'status'   => 'warn',
                'score'    => 20,
                'messages' => array( '正文过短' ),
            );
        }

        // 计算标题之间的距离
        $positions = array();
        $offset = 0;
        foreach ( $headings as $h ) {
            $pos = mb_stripos( $plain, $h['text'], $offset );
            if ( $pos !== false ) {
                $positions[] = $pos;
                $offset = $pos + mb_strlen( $h['text'] );
            }
        }

        if ( count( $positions ) >= 2 ) {
            $gaps = array();
            for ( $i = 1; $i < count( $positions ); $i++ ) {
                $gaps[] = $positions[ $i ] - $positions[ $i - 1 ];
            }

            $avg_gap = array_sum( $gaps ) / count( $gaps );
            $max_gap = max( $gaps );
            $min_gap = min( $gaps );

            // 均匀性评估
            if ( $avg_gap > 0 ) {
                $variance = 0;
                foreach ( $gaps as $gap ) {
                    $variance += pow( ( $gap - $avg_gap ) / $avg_gap, 2 );
                }
                $cv = sqrt( $variance / count( $gaps ) ); // 变异系数

                if ( $cv < 0.5 ) {
                    $score += 50;
                    $messages[] = '标题分布较均匀，内容节奏良好';
                } elseif ( $cv < 1.0 ) {
                    $score += 30;
                    $messages[] = '标题分布基本合理，个别段落偏长';
                } else {
                    $score += 10;
                    $messages[] = '标题分布不均匀，建议调整段落长度使标题更均匀';
                }
            }

            // 最大间隔检查
            if ( $max_gap > $total_len * 0.5 ) {
                $messages[] = '存在超过50%正文的空白区间，建议增加分节标题';
            }

            // 开头是否有标题（前200字）
            if ( $positions[0] <= 200 ) {
                $score += 25;
            } else {
                $messages[] = '第一个标题出现在' . $positions[0] . '字处，建议前200字内出现小节标题';
            }
        }

        // 每个H2下的平均字数
        $h2_positions = array();
        foreach ( $headings as $h ) {
            if ( $h['level'] === 2 ) {
                $pos = mb_stripos( $plain, $h['text'] );
                if ( $pos !== false ) $h2_positions[] = $pos;
            }
        }
        if ( count( $h2_positions ) >= 2 ) {
            $h2_gaps = array();
            for ( $i = 1; $i < count( $h2_positions ); $i++ ) {
                $h2_gaps[] = $h2_positions[ $i ] - $h2_positions[ $i - 1 ];
            }
            $avg_h2_gap = array_sum( $h2_gaps ) / count( $h2_gaps );
            if ( $avg_h2_gap >= 150 && $avg_h2_gap <= 600 ) {
                $score += 25;
                $messages[] = '每节平均' . round( $avg_h2_gap ) . '字，长度适中';
            } elseif ( $avg_h2_gap > 600 ) {
                $messages[] = '每节平均' . round( $avg_h2_gap ) . '字，偏长，建议控制在150-500字/节';
            } else {
                $messages[] = '每节平均' . round( $avg_h2_gap ) . '字，偏短';
            }
        }

        $score = max( 0, min( 100, $score ) );

        return array(
            'status'   => $score >= 60 ? 'good' : ( $score >= 40 ? 'warn' : 'bad' ),
            'score'    => $score,
            'messages' => $messages,
        );
    }

    /**
     * 生成 AI 优化建议
     */
    private function generate_suggestions( $headings, $keyword, $title ) {
        $suggestions = array();

        // 获取所有H2标题文本
        $h2_texts = array();
        foreach ( $headings as $h ) {
            if ( $h['level'] === 2 ) {
                $h2_texts[] = $h['text'];
            }
        }

        if ( empty( $h2_texts ) ) {
            $suggestions[] = array(
                'type'    => 'critical',
                'message' => '文章缺少H2标题结构，建议至少添加3-5个H2标题来分节',
            );
            return $suggestions;
        }

        // 基于文章标题推荐H2标题变体（融入长尾关键词）
        if ( ! empty( $keyword ) ) {
            $kw_variants = $this->get_keyword_variants( $keyword, $h2_texts );
            if ( ! empty( $kw_variants ) ) {
                foreach ( $kw_variants as $i => $variant ) {
                    $suggestions[] = array(
                        'type'    => 'improve',
                        'index'   => $i,
                        'original' => $h2_texts[ $i ] ?? '',
                        'suggested' => $variant,
                        'message' => '建议将H2标题「' . ($h2_texts[$i] ?? '') . '」优化为「' . $variant . '」，融入关键词变体',
                    );
                }
            }
        }

        // 结构优化建议
        if ( count( $h2_texts ) < 3 ) {
            $suggestions[] = array(
                'type'    => 'warning',
                'message' => 'H2标题数量偏少（' . count( $h2_texts ) . '个），建议增加到3-5个',
            );
        }

        // 首节建议
        $first_h2 = $h2_texts[0] ?? '';
        if ( ! empty( $keyword ) && mb_stripos( $first_h2, $keyword ) === false ) {
            $suggestions[] = array(
                'type'    => 'improve',
                'message' => '第一个H2标题建议包含核心关键词「' . $keyword . '」',
                'suggested' => $keyword . '：核心要点与背景',
            );
        }

        // 无关键词时给出通用建议
        if ( empty( $keyword ) ) {
            $suggestions[] = array(
                'type'    => 'improve',
                'message' => '建议在AI生成时填写关键词，可获得更精准的标题优化建议',
            );
        }

        // 结构良好时的肯定性建议
        if ( count( $h2_texts ) >= 3 && empty( $keyword ) ) {
            // 已经有H2且无关键词，仅返回通用建议（上面已处理）
        } elseif ( count( $h2_texts ) >= 3 && empty( $suggestions ) ) {
            // 3个以上H2，关键词已全覆盖，给出正面评价
            $suggestions[] = array(
                'type'    => 'improve',
                'message' => '标题结构良好！建议定期检查内容更新，保持H2标题与内容同步',
            );
        } elseif ( count( $h2_texts ) >= 3 && $suggestions !== array() ) {
            // 有建议但结构还行，追加一条积极建议
            $has_critical = false;
            foreach ( $suggestions as $sg ) {
                if ( $sg['type'] === 'critical' ) { $has_critical = true; break; }
            }
            if ( ! $has_critical ) {
                $suggestions[] = array(
                    'type'    => 'improve',
                    'message' => '整体结构良好，按上述建议微调后效果更佳',
                );
            }
        }

        return $suggestions;
    }

    /**
     * 获取关键词变体建议
     */
    private function get_keyword_variants( $keyword, $existing_h2 ) {
        $variants = array();
        $prefixes = array( '什么是', '如何', '为什么', '如何选择', '的优势', '的注意事项', '的实用技巧', '的常见误区', '的步骤详解', '的方法与技巧' );
        $added = 0;

        foreach ( $existing_h2 as $i => $h2 ) {
            if ( $added >= 3 ) break;
            if ( mb_stripos( $h2, $keyword ) !== false ) continue;
            if ( mb_stripos( $h2, $keyword ) === false ) {
                // 尝试加前缀
                $prefix = $prefixes[ $added % count( $prefixes ) ];
                $suggested = $prefix . $keyword;
                if ( mb_strlen( $suggested ) <= 30 ) {
                    $variants[ $i ] = $suggested;
                    $added++;
                }
            }
        }

        return $variants;
    }

    /**
     * 计算标题结构总分
     */
    private function calculate_heading_score( $h1, $h2, $h3, $kw, $dist ) {
        $weights = array(
            'h1'    => 10,
            'h2'    => 30,
            'h3'    => 15,
            'kw'    => 30,
            'dist'  => 15,
        );

        $total = $h1['score'] * $weights['h1']
               + $h2['score'] * $weights['h2']
               + $h3['score'] * $weights['h3']
               + $kw['score'] * $weights['kw']
               + $dist['score'] * $weights['dist'];

        $weight_sum = array_sum( $weights );
        return round( $total / $weight_sum );
    }

    /**
     * AJAX: 单篇分析
     */
    public static function ajax_analyze() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( '权限不足' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( '缺少文章ID' );
        }

        $analyzer = self::get_instance();
        $result   = $analyzer->analyze( $post_id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    // （原 ajax_batch_scan() 46 行死方法——无注册无调用，按审计建议 #11 已删除）

    /**
     * AJAX: 批量检查文章的优化状态
     */
    public static function ajax_check_optimized() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $post_ids = isset( $_POST['post_ids'] ) ? (array) $_POST['post_ids'] : array();
        $post_ids = array_map( 'absint', $post_ids );
        $post_ids = array_filter( $post_ids );

        $status_map = array();
        foreach ( $post_ids as $id ) {
            $optimized = get_post_meta( $id, 'yimai_gso_heading_optimized', true );
            $status_map[ $id ] = ! empty( $optimized );
        }

        wp_send_json_success( $status_map );
    }

    /**
     * AI 生成 H2 标题优化建议
     *
     * @param int $post_id 文章ID
     * @return array|WP_Error { optimizations: array, overall: string }
     */
    public function generate_ai_suggestions( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', '文章不存在' );
        }

        $content   = $post->post_content;
        $title     = $post->post_title;
        $headings  = $this->extract_headings( $content );

        // 提取所有 H2 标题
        $h2_texts = array();
        foreach ( $headings as $h ) {
            if ( $h['level'] === 2 ) {
                $h2_texts[] = $h['text'];
            }
        }

        if ( empty( $h2_texts ) ) {
            return new WP_Error( 'no_h2', '文章没有H2标题，无需优化' );
        }

        // 获取关键词
        $keywords = get_post_meta( $post_id, 'yimai_gso_keywords', true );
        if ( ! empty( $keywords ) && is_string( $keywords ) ) {
            $kw_arr = explode( ',', $keywords );
        } elseif ( is_array( $keywords ) ) {
            $kw_arr = $keywords;
        } else {
            $kw_arr = array();
        }
        $primary_keyword = trim( $kw_arr[0] ?? '' );

        // 构建 AI 请求
        $h2_list_text = '';
        foreach ( $h2_texts as $i => $text ) {
            $h2_list_text .= ( $i + 1 ) . '. "' . $text . "\"\n";
        }

        $sys_prompt = "你是一位资深SEO文案编辑和标题优化专家。请分析文章的H2标题结构，给出具体的优化建议。

要求：
1. 评估每个H2标题的SEO友好度（是否含关键词、是否吸引点击、长度是否适中）
2. 对需要优化的H2标题给出优化后的新标题（15-28字，自然融入核心关键词）
3. 保留已经包含关键词、表达精炼的H2标题（标记为无需优化）
4. 优化后的标题要符合中文阅读习惯，不要生硬堆砌关键词
5. 给出1-2条整体结构建议

请严格按以下JSON格式返回，不要返回其他内容：
{
  \"optimizations\": [
    {
      \"original\": \"当前H2标题\",
      \"suggested\": \"优化后的标题\",
      \"reason\": \"优化原因\",
      \"keep\": false
    }
  ],
  \"overall\": \"整体优化建议\"
}
注意：如果某个标题已经很好，不需要优化，请设置 \"keep\": true，suggested 留空。";

        $user_message = "文章标题：{$title}\n";
        if ( ! empty( $primary_keyword ) ) {
            $user_message .= "核心关键词：{$primary_keyword}\n";
        }
        $user_message .= "\n当前 H2 标题列表（共 " . count( $h2_texts ) . " 个）：\n{$h2_list_text}";

        // 调用 AI API
        $generator = new YIMAI_GSO_AI_Generator();
        $response  = $generator->call_api( array(
            array( 'role' => 'system', 'content' => $sys_prompt ),
            array( 'role' => 'user',   'content' => $user_message ),
        ), 2048 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $content_raw = $response['choices'][0]['message']['content'] ?? '';
        $content_raw = preg_replace( '/^```json\s*/m', '', $content_raw );
        $content_raw = preg_replace( '/^```\s*/m', '', $content_raw );
        $content_raw = trim( $content_raw );

        $data = json_decode( $content_raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $data['optimizations'] ) ) {
            // 解析失败回退
            return array(
                'optimizations' => array(),
                'overall'       => 'AI分析暂时无法处理，请稍后重试',
            );
        }

        $result = array(
            'optimizations' => $data['optimizations'],
            'overall'       => $data['overall'] ?? '',
        );

        // 缓存到 post_meta
        update_post_meta( $post_id, 'yimai_gso_heading_optimization', $result );

        return $result;
    }

    /**
     * AJAX: 生成 AI 优化建议
     */
    public static function ajax_optimize() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 权限：仅 SEO/测试版
        if ( true && ! true ) {
            wp_send_json_error( array( 'message' => '请检查站点设置后使用标题结构分析功能。' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => '缺少文章ID' ) );
        }

        $analyzer = self::get_instance();
        $result   = $analyzer->generate_ai_suggestions( $post_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: 应用选中的优化（替换文章中的 H2 标题）
     */
    public static function ajax_apply_optimization() {
        check_ajax_referer( 'yimai_gso_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        // 权限：仅 SEO/测试版
        if ( true && ! true ) {
            wp_send_json_error( array( 'message' => '请检查站点设置后使用标题结构分析功能。' ) );
        }

        $post_id       = absint( $_POST['post_id'] ?? 0 );
        $optimizations = isset( $_POST['optimizations'] ) ? json_decode( wp_unslash( $_POST['optimizations'] ), true ) : array();

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => '文章不存在' ) );
        }

        if ( empty( $optimizations ) || ! is_array( $optimizations ) ) {
            wp_send_json_error( array( 'message' => '请选择至少一个需要优化的标题' ) );
        }

        $post = get_post( $post_id );

        // 安全校验
        if ( $post->post_author != get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
            wp_send_json_error( array( 'message' => '无权修改此文章' ) );
        }

        $content = $post->post_content;
        $applied = 0;

        foreach ( $optimizations as $opt ) {
            $original = trim( $opt['original'] ?? '' );
            $suggested = trim( $opt['suggested'] ?? '' );
            if ( empty( $original ) || empty( $suggested ) ) {
                continue;
            }

            // 在 H2 标签内替换标题文本
            // 匹配 <h2...>原标题</h2> → <h2...>新标题</h2>
            $pattern = '/<h([2])[^>]*>\s*' . preg_quote( $original, '/' ) . '\s*<\/h\1>/isu';
            $replacement = '<h2>' . $suggested . '</h2>';
            $new_content = preg_replace( $pattern, $replacement, $content, 1, $count );
            if ( $count > 0 ) {
                $content = $new_content;
                $applied++;
            } else {
                // 尝试更宽松的匹配（可能包含HTML标签内的标题文本）
                $pattern2 = '/<h2[^>]*>.*?' . preg_quote( $original, '/' ) . '.*?<\/h2>/isu';
                $content = preg_replace( $pattern2, $replacement, $content, 1, $count2 );
                if ( $count2 > 0 ) {
                    $applied++;
                }
            }
        }

        if ( $applied === 0 ) {
            wp_send_json_error( array( 'message' => '未找到匹配的标题，可能内容已被修改' ) );
        }

        // 更新文章
        $updated = wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $content,
        ), true );

        if ( is_wp_error( $updated ) ) {
            wp_send_json_error( array( 'message' => '写入失败：' . $updated->get_error_message() ) );
        }

        // 清除优化建议缓存
        delete_post_meta( $post_id, 'yimai_gso_heading_optimization' );

        // 标记为已优化
        update_post_meta( $post_id, 'yimai_gso_heading_optimized', '1' );

        // 重新分析标题结构
        $analyzer = self::get_instance();
        $new_analysis = $analyzer->analyze( $post_id );

        wp_send_json_success( array(
            'applied'  => $applied,
            'analysis' => $new_analysis,
        ) );
    }

    /* ================================================
     * GEO 版标题分析（第3层新增）
     * ================================================ */

    /**
     * 检测问题式标题
     * AI 搜索引擎偏好问题式结构，更容易作为直接答案被提取
     *
     * @param array $headings  extract_headings() 的输出
     * @return array { count, total_h2, ratio, question_headings, score, tips }
     */
    public function check_question_style_headings( $headings ) {
        $question_starters = array(
            '如何', '怎么', '为什么', '什么是', '哪', '谁', '多少',
            '能不能', '是不是', '有哪些', '怎样', '为啥', '咋',
            '如何才能', '应该如何', '该如何',
        );

        $h2_headings = array_filter( $headings, function( $h ) {
            return $h['level'] === 2;
        } );

        $total_h2      = count( $h2_headings );
        $question_list = array();

        foreach ( $h2_headings as $h ) {
            $text = $h['text'];
            foreach ( $question_starters as $starter ) {
                if ( mb_strpos( $text, $starter ) === 0 ) {
                    $question_list[] = $text;
                    break;
                }
            }
        }

        $question_count = count( $question_list );
        $ratio          = $total_h2 > 0 ? round( $question_count / $total_h2 * 100 ) : 0;

        // 评分
        if ( $ratio >= 50 ) {
            $score = 100;
            $level = 'good';
        } elseif ( $ratio >= 30 ) {
            $score = 70;
            $level = 'ok';
        } elseif ( $ratio > 0 ) {
            $score = 40;
            $level = 'warn';
        } else {
            $score = 10;
            $level = 'bad';
        }

        $tips = array();

        if ( $ratio >= 50 ) {
            $tips[] = sprintf( '问题式标题占比 %d%%（%d/%d），AI 搜索引擎偏好此结构', $ratio, $question_count, $total_h2 );
        } elseif ( $ratio > 0 ) {
            $tips[] = sprintf( '问题式标题占比 %d%%（%d/%d），建议将部分陈述式标题改为问题式', $ratio, $question_count, $total_h2 );
        } else {
            $tips[] = '未检测到问题式标题，建议将至少 30% 的 H2 改为问题式（如"如何..."、"什么是..."）';
        }

        // 建议将哪些陈述式标题改为问题式
        $suggestions = array();
        foreach ( $h2_headings as $h ) {
            $text = $h['text'];
            $is_question = false;
            foreach ( $question_starters as $starter ) {
                if ( mb_strpos( $text, $starter ) === 0 ) {
                    $is_question = true;
                    break;
                }
            }
            if ( ! $is_question && mb_strlen( $text ) >= 6 ) {
                $suggestions[] = $text;
            }
        }

        return array(
            'count'            => $question_count,
            'total_h2'         => $total_h2,
            'ratio'            => $ratio,
            'question_headings'=> $question_list,
            'score'            => $score,
            'level'            => $level,
            'tips'             => $tips,
            'suggestions'      => array_slice( $suggestions, 0, 5 ),
        );
    }

    /**
     * 检查标题与内容首句的对应关系
     * 检测"标题问 - 内容答"模式是否成立
     *
     * @param array  $headings extract_headings() 的输出
     * @param string $content  文章 HTML 内容
     * @return array { mismatches, score, tips }
     */
    public function check_heading_content_correspondence( $headings, $content ) {
        $mismatches = array();
        $total      = 0;
        $matched    = 0;

        // 按 H2 块分割内容
        if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>(.*?)(?=<h2|$)/isu', $content, $blocks, PREG_SET_ORDER ) ) {
            foreach ( $blocks as $block ) {
                $total++;
                $h_text = wp_strip_all_tags( $block[1] );
                $body   = wp_strip_all_tags( $block[2] );

                // 提取标题关键词（取前 6 个字作为核心词）
                $title_core = mb_substr( $h_text, 0, min( 6, mb_strlen( $h_text ) ) );

                // 检测内容首句是否包含标题关键词
                $first_sentence = '';
                if ( preg_match( '/^(.{10,40}?)[。！？\.!?]/u', trim( $body ), $fm ) ) {
                    $first_sentence = $fm[1];
                } else {
                    $first_sentence = mb_substr( trim( $body ), 0, 40 );
                }

                // 判断对应关系
                $corresponds = false;

                // 如果标题是问题，检查首句是否直接回答
                $is_question = preg_match( '/[？?]$/u', $h_text )
                    || preg_match( '/^(如何|怎么|为什么|什么是|哪|谁|多少)/u', $h_text );

                if ( $is_question ) {
                    // 问题式标题：首句应该直接回答
                    $answer_markers = array( '是', '可以', '需要', '应该', '通过', '首先', '一般', '通常' );
                    foreach ( $answer_markers as $m ) {
                        if ( mb_strpos( $first_sentence, $m ) === 0 || mb_strpos( $first_sentence, $m ) !== false ) {
                            $corresponds = true;
                            break;
                        }
                    }
                } else {
                    // 陈述式标题：首句应该包含标题关键词
                    $core_chars = preg_split( '//u', $title_core, -1, PREG_SPLIT_NO_EMPTY );
                    $match_count = 0;
                    foreach ( $core_chars as $c ) {
                        if ( mb_strpos( $first_sentence, $c ) !== false ) {
                            $match_count++;
                        }
                    }
                    $overlap_ratio = count( $core_chars ) > 0 ? $match_count / count( $core_chars ) : 0;
                    $corresponds = $overlap_ratio >= 0.4;
                }

                if ( $corresponds ) {
                    $matched++;
                } else {
                    $mismatches[] = array(
                        'heading'         => $h_text,
                        'first_sentence'  => $first_sentence,
                        'is_question'     => $is_question,
                    );
                }
            }
        }

        $score = $total > 0 ? round( $matched / $total * 100 ) : 100;
        $tips  = array();

        if ( count( $mismatches ) > 0 ) {
            $tips[] = sprintf( '%d/%d 个 H2 标题与内容首句对应不佳，建议检查', count( $mismatches ), $total );
        } else {
            $tips[] = '所有 H2 标题与内容首句对应良好';
        }

        return array(
            'mismatches' => $mismatches,
            'score'      => $score,
            'total'      => $total,
            'matched'    => $matched,
            'tips'       => $tips,
        );
    }

    /**
     * GEO 标题综合评分
     * 权重：问题式标题 40% + 层级结构 30% + 内容对应 30%
     *
     * @param int $post_id
     * @return array
     */
    public function calculate_geo_heading_score( $post_id ) {
        $post     = get_post( $post_id );
        if ( ! $post ) return array( 'score' => 0, 'level' => 'bad', 'details' => array() );

        $content  = $post->post_content;
        $headings = $this->extract_headings( $content );

        $question_result = $this->check_question_style_headings( $headings );
        $corr_result     = $this->check_heading_content_correspondence( $headings, $content );

        // 层级结构基础分：直接使用轻量级 H1/H2 检查，避免调用完整 analyze()
        $h1_result = $this->check_h1( $headings );
        $h2_result = $this->check_h2_structure( $headings );
        $h3_result = $this->check_h3_structure( $headings );

        // 将 H1/H2/H3 的综合状态映射为 0-100 分数
        $struct_score = 70; // 默认基础分
        if ( ! empty( $h1_result['ok'] ) ) $struct_score += 10;
        else $struct_score -= 10;
        if ( ! empty( $h2_result['ok'] ) ) $struct_score += 10;
        else $struct_score -= 5;
        if ( ! empty( $h3_result['ok'] ) ) $struct_score += 5;
        $struct_score = max( 0, min( 100, $struct_score ) );

        // 加权计算
        $geo_score = round(
            $question_result['score'] * 0.4 +
            $struct_score * 0.3 +
            $corr_result['score'] * 0.3
        );

        $level = 'good';
        if ( $geo_score < 60 ) $level = 'warn';
        if ( $geo_score < 40 ) $level = 'bad';

        return array(
            'score'   => $geo_score,
            'level'   => $level,
            'details' => array(
                'question_style' => $question_result,
                'correspondence' => $corr_result,
            ),
            'tips'    => array_merge( $question_result['tips'], $corr_result['tips'] ),
        );
    }
}
