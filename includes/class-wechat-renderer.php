<?php
/**
 * 公众号正文渲染器：WordPress 文章 → 微信公众号草稿箱可用的 HTML
 *
 * 为什么需要这一层：微信正文不是网页。它只认「受限 HTML 子集 + 内联 style」——
 * class / id / 外部样式表全部被剥掉，外链图片在草稿箱里显示成白框，script 直接消失，
 * 代码块里没转义的标签会被当成结构吃掉。把 the_content 原样丢给 draft/add，
 * 出来的要么是一坨没排版的纯文本，要么结构错乱直接 53503。
 *
 * 所以这里按固定顺序做五件事（顺序不能换）：
 *   1) DOM 解析。必须在 HTML 前面拼 <?xml encoding="utf-8"?>，否则 libxml 按
 *      ISO-8859-1 理解，中文全变乱码 —— 这是本文件最经典的坑。
 *   2) 整节点删除危险标签，非白名单标签 unwrap（保留文字、丢掉标签）。
 *   3) 清属性，再按标签重建内联 style。清完再写：作者的 style 里常有 float /
 *      width / position，在手机宽度下会把排版撑破，留着不如统一。
 *   4) 图片转存（download_url → 压缩 → uploadimg → 换 src）。微信只肯显示自己
 *      域名下的图片，外链一律白框。
 *   5) 外链转 span + 文末脚注。微信正文里的外链点不动，唯一能让读者拿到地址的
 *      办法就是把 URL 摆到文末。
 *
 * 最后两道闸：正文 > 20000 字或 > 1MB 微信会静默拒收，必须在提交前拦下来，
 * 并把「为什么 / 怎么办」写进 WP_Error 的 message 里，运营才知道该拆篇。
 *
 * @package 一麦GSO SEO-GEO
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class YIMAI_GSO_Wx_Renderer {

    /** 微信正文允许的标签（限定子集，其余一律 unwrap） */
    const ALLOWED = array(
        'section', 'p', 'span', 'img', 'a', 'strong', 'em', 'b', 'i', 'br', 'hr',
        'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'pre', 'code', 'figure', 'figcaption',
    );

    /** 整节点删除：这些标签留在正文里，轻则被微信剥掉，重则让整篇草稿结构错乱 */
    const DROP = array(
        'script', 'style', 'iframe', 'form', 'input', 'button', 'noscript',
        'svg', 'audio', 'video',
    );

    /** 允许保留的属性：其余（class / id / data-* / on* / style）全删，由 apply_style() 重建 */
    const KEEP_ATTRS = array( 'src', 'href', 'alt', 'colspan', 'rowspan' );

    /**
     * 代码块内按「容器」处理的标签（只取文字、不保留标签字样）
     *
     * 用来区分两种来源：高亮插件在 <code> 里插的 <span class="k"> 属于渲染噪音，
     * 把标签字样显示出来纯属灾难；而作者写的 <div> / <p> / <ul> 是代码示例本身，
     * 必须还原成源码字样。高亮器不会产出块级标签，代码示例也很少只有 inline 标签，
     * 这条线就是按这个差异划的。
     */
    const CODE_INLINE = array( 'span', 'b', 'i', 'em', 'strong', 'a', 'code', 'mark', 'u', 'small', 'sub', 'sup' );

    /** 最外层包裹样式：字色字号行高三件套，word-break 防长英文撑破手机宽度 */
    const WRAP_STYLE = 'font-size:16px;line-height:1.75;color:#3f3f3f;word-break:break-word';

    /**
     * 标签 → 内联样式（抄 doocs/md 的基调：16px / 1.75 行高 / #3f3f3f / 段距 1.2em）
     *
     * 微信正文只认内联 style，class 与外部样式表全部被剥掉，所以排版全押在这张表上。
     * 表里没有的标签（span / strong / section …）不加 style：它们靠父级继承就够，
     * 多写反而会盖掉父级的行高与字色。
     */
    const STYLE = array(
        'p'          => 'margin:0 0 1.2em;line-height:1.75;font-size:16px;color:#3f3f3f;letter-spacing:.5px',
        'h1'         => 'margin:1.6em 0 .8em;font-size:22px;font-weight:700;line-height:1.4;color:#1a1a1a',
        'h2'         => 'margin:1.5em 0 .7em;font-size:19px;font-weight:700;line-height:1.4;color:#1a1a1a;border-left:4px solid #ff8a3d;padding-left:10px',
        'h3'         => 'margin:1.3em 0 .6em;font-size:17px;font-weight:700;color:#1a1a1a',
        'blockquote' => 'margin:1.2em 0;padding:12px 16px;border-left:3px solid #ff8a3d;background:#fafafa;color:#666;font-size:15px;line-height:1.7',
        'ul'         => 'margin:0 0 1.2em;padding-left:1.4em;list-style:disc',
        'ol'         => 'margin:0 0 1.2em;padding-left:1.4em;list-style:decimal',
        'li'         => 'margin:.4em 0;line-height:1.75;font-size:16px;color:#3f3f3f',
        'img'        => 'max-width:100%;height:auto;display:block;margin:16px auto;border-radius:4px',
        'a'          => 'color:#576b95;text-decoration:none',
        'code'       => 'font-family:Menlo,Consolas,monospace;font-size:13px;background:#f6f8fa;padding:2px 5px;border-radius:3px;color:#c7254e',
        'pre'        => 'margin:1.2em 0;padding:14px 16px;background:#f6f8fa;border-radius:6px;overflow-x:auto',
        'th'         => 'border:1px solid #e5e6eb;padding:8px 10px;background:#f7f8fa;font-weight:600;font-size:14px;text-align:left',
        'td'         => 'border:1px solid #e5e6eb;padding:8px 10px;font-size:14px',
        'table'      => 'width:100%;border-collapse:collapse;margin:1.2em 0;font-size:14px',
        // 下面几个是白名单里但规格没给值的标签：不给样式会紧贴上下文字或与正文同级，
        // 看起来像漏排（h4-h6 尤其明显：微信会把浏览器的默认标题样式剥掉）
        'h4'         => 'margin:1.2em 0 .5em;font-size:16px;font-weight:700;line-height:1.5;color:#1a1a1a',
        'h5'         => 'margin:1.1em 0 .4em;font-size:15px;font-weight:700;line-height:1.5;color:#1a1a1a',
        'h6'         => 'margin:1em 0 .4em;font-size:14px;font-weight:700;line-height:1.5;color:#666',
        'hr'         => 'border:none;border-top:1px solid #e5e6eb;margin:2em 0',
        'figcaption' => 'font-size:13px;color:#94a3b8;text-align:center;margin:-6px 0 1.2em',
    );

    /** 正文总长闸门：微信按字符数算，超了静默拒收 */
    const MAX_CHARS = 20000;
    const MAX_BYTES = 1048576;

    /** 图片流水线参数 */
    const MAX_IMAGES     = 20;      // 单篇最多转存 20 张，再多会拖到超时
    const MAX_WIDTH      = 1080;    // 微信正文宽度上限，超宽图会被压扁
    const QUALITY        = 82;      // 首次压缩质量
    const QUALITY_LOW    = 70;      // 仍超 900KB 时的兜底质量
    const MAX_IMAGE_SIZE = 921600;  // 900KB：uploadimg 硬上限 1MB，留余量

    /**
     * 本次渲染产生的告警（图片没转存、脚注为空等）。
     * 静态属性是因为 render() 只能返回 string|WP_Error，告警需要另一条出口给后台/同步器读；
     * 每次 render 开头会重置，所以不会跨文章串味。
     */
    private static $warnings = array();

    /* ==================== 对外接口 ==================== */

    /**
     * 生成可直接提交 draft/add 的正文 HTML
     *
     * @param WP_Post $post
     * @return string|WP_Error
     */
    public static function render( WP_Post $post ) {
        return self::build( $post, true );
    }

    /**
     * 生成后台预览用 HTML：不压缩图片、不调任何微信接口
     *
     * 预览的意义是「排版对不对」，不是「能不能发」，所以图片保留原 src 让浏览器直接加载。
     *
     * @param WP_Post $post
     * @return string|WP_Error
     */
    public static function render_preview( WP_Post $post ) {
        return self::build( $post, false );
    }

    /**
     * 取上一次 render 的告警（图片转存失败原因、超量图片等）
     *
     * @return array
     */
    public static function get_warnings() {
        return self::$warnings;
    }

    /* ==================== 主流程 ==================== */

    /**
     * @param WP_Post $post
     * @param bool    $with_images true=走图片转存（render），false=保留原图（render_preview）
     * @return string|WP_Error
     */
    private static function build( $post, $with_images ) {
        self::$warnings = array();

        if ( ! is_object( $post ) || ! isset( $post->post_content ) ) {
            return new WP_Error( 'wx_render_input', '没有拿到文章内容，无法生成公众号正文' );
        }

        // 1) 跑 the_content：短代码、区块、wpautop 都在这一步生效。
        //    直接用 post_content 会带出 <!-- wp:paragraph --> 这类区块注释。
        //    有些过滤器（自动摘要、相关阅读）依赖全局 $post，所以临时挂上再还原。
        $prev_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        $html = apply_filters( 'the_content', (string) $post->post_content );
        if ( null === $prev_post ) {
            unset( $GLOBALS['post'] );
        } else {
            $GLOBALS['post'] = $prev_post;
        }

        $html = trim( (string) $html );
        if ( '' === $html ) {
            return new WP_Error( 'wx_empty', '正文是空的，没有内容可以同步到草稿箱' );
        }

        // 1.5) 粗估闸：在碰图片之前先拦明显超长的文章。
        // 为什么不能只靠第 11 步：图片流水线是「真的往微信传图」，一篇 25000 字的文章
        // 会先把 N 张图全部传完（消耗 uploadimg 日配额、耗时几十秒）才在最后报 wx_too_long。
        // 这里只按纯文本字数粗估（HTML 字节数不可比：作者标记越多原文越大，清洗后反而更小），
        // 第 11 步的精确闸保留作兜底 —— 脚注 URL、图片提示段落会让最终文字量比这里多。
        if ( mb_strlen( wp_strip_all_tags( $html ) ) > self::MAX_CHARS ) {
            return new WP_Error( 'wx_too_long', '正文超过 20000 字，微信拒收，请拆分为上下篇' );
        }

        // 2) 解析。xml encoding 声明不能省，否则中文全乱码；
        //    外层 div#wx-root 给后续操作一个稳定根节点，也顺便吃掉游离的文本节点。
        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $prev_errors = libxml_use_internal_errors( true );
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="wx-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev_errors );

        $root = self::root_node( $doc );
        if ( ! $root ) {
            return new WP_Error( 'wx_render', '正文解析失败（HTML 结构无法识别），请检查文章里是否有未闭合的标签' );
        }

        // 3-6) 删节点 → unwrap → 清属性 → 内联样式
        self::clean_children( $root );

        // 7) 图片转存
        if ( $with_images ) {
            self::process_images( $doc, $root );
        }

        // 8) 外链 → span + 脚注
        self::process_links( $doc, $root );

        // 10) 序列化并整体包裹
        $inner = '';
        $children = array();
        foreach ( $root->childNodes as $child ) {
            $children[] = $child;
        }
        foreach ( $children as $child ) {
            $inner .= (string) $doc->saveHTML( $child );
        }
        $doc = null;

        $out = '<section style="' . self::WRAP_STYLE . '">' . $inner . '</section>';

        // 11) 长度闸门（这里是精确值：清洗、内联样式、脚注都已就位）
        $text = wp_strip_all_tags( $out );
        // 只剩脚本 / 空段落时不能返回一个空壳 section 让上层建草稿；
        // 但纯图片的文章是合法内容，所以只在「没文字且没图」时才判空。
        if ( '' === trim( $text ) && false === strpos( $out, '<img ' ) ) {
            return new WP_Error( 'wx_empty', '正文清洗后没有可显示的内容（只剩脚本或空段落），请检查文章内容' );
        }
        if ( mb_strlen( $text ) > self::MAX_CHARS ) {
            return new WP_Error( 'wx_too_long', '正文超过 20000 字，微信拒收，请拆分为上下篇' );
        }
        if ( strlen( $out ) > self::MAX_BYTES ) {
            return new WP_Error( 'wx_too_big', '正文超过 1MB，请减少内联图片' );
        }

        return (string) $out;
    }

    /**
     * 取 div#wx-root
     *
     * getElementById 在 libxml 里依赖 HTML 解析器把 id 当 ID 型属性；多数版本可用，
     * 但换 libxml 版本/换解析选项后可能返回 null，静默失败会让整篇正文变成空。
     * 所以保留 XPath 兜底 —— 宁可多写三行，也不要线上出现「正文解析失败」且无法解释。
     *
     * @param DOMDocument $doc
     * @return DOMElement|null
     */
    private static function root_node( DOMDocument $doc ) {
        $root = $doc->getElementById( 'wx-root' );
        if ( $root instanceof DOMElement ) {
            return $root;
        }
        $xpath = new DOMXPath( $doc );
        $nodes = $xpath->query( '//*[@id="wx-root"]' );
        if ( $nodes && $nodes->length > 0 ) {
            return $nodes->item( 0 );
        }
        return null;
    }

    /* ==================== 3-6：清洗 / 白名单 / 属性 / 样式 ==================== */

    /**
     * 递归清洗（就地修改）
     *
     * 用 while + 索引遍历活的 childNodes，而不是 foreach 快照：删节点 / unwrap 后
     * 索引不推进，天然重新处理「被提升到当前位置」的节点，一趟扫完且不会错位。
     *
     * @param DOMNode $parent
     */
    private static function clean_children( DOMNode $parent ) {
        $i = 0;
        while ( $i < $parent->childNodes->length ) {
            $node = $parent->childNodes->item( $i );
            if ( ! $node ) {
                break;
            }

            // 区块注释会原样占位，且可能带编辑器的内部标记，统一删掉
            if ( XML_COMMENT_NODE === $node->nodeType ) {
                $parent->removeChild( $node );
                continue;
            }
            if ( XML_ELEMENT_NODE !== $node->nodeType ) {
                $i++;
                continue;
            }

            $tag = strtolower( $node->nodeName );

            if ( in_array( $tag, self::DROP, true ) ) {
                $parent->removeChild( $node );
                continue;
            }

            if ( ! in_array( $tag, self::ALLOWED, true ) ) {
                // unwrap：子节点按原顺序前移，然后丢掉标签本身。
                // 下一个循环仍读 $i，正好落在第一个被提升的节点上。
                while ( $node->firstChild ) {
                    $parent->insertBefore( $node->firstChild, $node );
                }
                $parent->removeChild( $node );
                continue;
            }

            self::strip_attributes( $node );
            self::apply_style( $node );

            if ( 'pre' === $tag ) {
                // 代码块内部整体当文本处理：不再往下递归，也不让 img/a 的规则进来
                self::escape_code_block( $node );
                $i++;
                continue;
            }

            self::clean_children( $node );
            $i++;
        }
    }

    /**
     * 清属性：只留 KEEP_ATTRS，其余全删（先收集再删，不在遍历中改 NamedNodeMap）
     *
     * @param DOMElement $node
     */
    private static function strip_attributes( DOMElement $node ) {
        $names = array();
        foreach ( $node->attributes as $attr ) {
            $names[] = $attr->name;
        }
        foreach ( $names as $name ) {
            if ( ! in_array( strtolower( $name ), self::KEEP_ATTRS, true ) ) {
                $node->removeAttribute( $name );
            }
        }
    }

    /**
     * 样式内联：按标签查 STYLE 表重建 style
     *
     * @param DOMElement $node
     */
    private static function apply_style( DOMElement $node ) {
        $tag = strtolower( $node->nodeName );
        if ( isset( self::STYLE[ $tag ] ) ) {
            $node->setAttribute( 'style', self::STYLE[ $tag ] );
        }
    }

    /**
     * 代码块转义
     *
     * 作者在 <pre> 里写裸标签（没转义）时，HTML 解析器已经把它变成了真实元素，
     * 直接序列化会被微信的清洗器当结构吃掉（<div> 只剩半截文字）。所以：
     *   - 文本子节点：nodeValue 原样收集
     *   - 元素子节点：先用 saveHTML 还原成源码文本，再统一按纯文本处理
     * 收集完把 pre 的子节点清空，只写回一个文本节点。注意不能直接把
     * htmlspecialchars 的结果塞进 createTextNode —— DOM 序列化文本节点时会再转义
     * 一次，输出会变成 &amp;lt;。正确做法是先转义（保证任何 < 都不可能是标签起点），
     * 再让 appendXML 把实体解析回字符，最后由 DOM 统一转义输出 &lt;。
     *
     * @param DOMElement $pre
     */
    private static function escape_code_block( DOMElement $pre ) {
        $doc = $pre->ownerDocument;
        $raw = self::code_block_source( $pre );

        while ( $pre->firstChild ) {
            $pre->removeChild( $pre->firstChild );
        }

        $escaped  = htmlspecialchars( $raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $fragment = $doc->createDocumentFragment();
        $ok       = '' !== $escaped && @$fragment->appendXML( $escaped );
        if ( $ok && $fragment->hasChildNodes() ) {
            $pre->appendChild( $fragment );
        } else {
            // appendXML 对非法 XML 字符会失败，退回文本节点：DOM 序列化时同样会转义
            $pre->appendChild( $doc->createTextNode( $raw ) );
        }
    }

    /**
     * 还原 <pre> 的源码文本
     *
     * 三种情况分开处理，全是踩过的坑：
     *   - code 是 <pre> 的正常搭档（core/code 区块、各种高亮插件都会包一层）→ 当容器，只取文字
     *   - code 内部的元素（高亮插件的 <span class="k"> 之类）→ 同样当容器只取文字，
     *     否则代码块里会冒出一堆 <span class="k"> 字面标签
     *   - 直接挂在 pre 下的其它元素 → 说明作者在 <pre> 里裸写了标签（没转义），被解析器
     *     变成了真实元素；用 saveHTML 还原成源码字样，代码块里才看得见 <div> 原文
     *
     * @param DOMNode $node
     * @param bool    $in_code 是否已经处在 code 容器内
     * @return string
     */
    private static function code_block_source( DOMNode $node, $in_code = false ) {
        $raw = '';
        foreach ( $node->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
                $raw .= (string) $child->nodeValue;
                continue;
            }
            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }
            $tag = strtolower( $child->nodeName );
            if ( 'code' === $tag || ( $in_code && in_array( $tag, self::CODE_INLINE, true ) ) ) {
                $raw .= self::code_block_source( $child, true );
                continue;
            }
            $raw .= (string) $node->ownerDocument->saveHTML( $child );
        }
        return $raw;
    }

    /* ==================== 7：图片转存 ==================== */

    /**
     * 逐张转存正文图片
     *
     * @param DOMDocument $doc
     * @param DOMElement  $root
     */
    private static function process_images( DOMDocument $doc, DOMElement $root ) {
        $images = array();
        foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
            $images[] = $img;
        }

        $handled = 0;
        $skipped = 0;
        foreach ( $images as $img ) {
            if ( ! $img->parentNode ) {
                continue;
            }
            $src = trim( (string) $img->getAttribute( 'src' ) );
            if ( '' === $src ) {
                $img->parentNode->removeChild( $img );
                continue;
            }

            // 已经是微信自家图床：微信能正常显示，转存纯属浪费配额
            $host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
            if ( false !== strpos( $host, 'qpic.cn' ) || false !== strpos( $host, 'qlogo.cn' ) ) {
                continue;
            }

            if ( $handled >= self::MAX_IMAGES ) {
                // 保留原 src 不删：宁可这一张显示不出来，也不要让读者以为这里本来没有图
                $skipped++;
                continue;
            }
            $handled++;

            $result = self::transfer_image( $src, $handled );
            if ( is_wp_error( $result ) ) {
                $alt = trim( (string) $img->getAttribute( 'alt' ) );
                self::$warnings[] = '第 ' . $handled . ' 张图片未能转存（' . ( '' !== $alt ? $alt : $src ) . '）：' . $result->get_error_message();
                self::replace_image_with_notice( $doc, $img, $alt );
                continue;
            }
            $img->setAttribute( 'src', (string) $result );
        }

        if ( $skipped > 0 ) {
            self::$warnings[] = '单篇图片超过 ' . self::MAX_IMAGES . ' 张，最后 ' . $skipped . ' 张未转存（微信不显示外链图，建议拆篇或删图）';
        }
    }

    /**
     * 单张图片：下载 → 压缩 → 上传，返回微信域名 url
     *
     * @param string $src
     * @param int    $index 第几张（用于文件名和告警定位）
     * @return string|WP_Error
     */
    private static function transfer_image( $src, $index ) {
        // download_url() 定义在 wp-admin/includes/file.php，只有后台请求会加载它；
        // cron / 前台 / WP-CLI / REST 前端入口默认没有这个文件，直接调就是
        // Call to undefined function → 整个请求 500，而且在 cron 里是静默发生的。
        // 所以在调用点就近自保：谁走图片流水线谁加载，render_preview() 不走这里也就不必付这个开销。
        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = download_url( $src, 30 );
        if ( is_wp_error( $tmp ) ) {
            return new WP_Error( 'wx_img_download', '原图下载失败：' . $tmp->get_error_message() );
        }

        $editor = wp_get_image_editor( $tmp );
        if ( is_wp_error( $editor ) ) {
            @unlink( $tmp );
            return new WP_Error( 'wx_img_editor', '图片格式不支持或文件损坏：' . $editor->get_error_message() );
        }

        // 只缩不放：原图比 1080 窄时硬 resize 到 1080 只会让图变糊、体积变大
        $size = $editor->get_size();
        if ( is_array( $size ) && ! empty( $size['width'] ) && (int) $size['width'] > self::MAX_WIDTH ) {
            $resized = $editor->resize( self::MAX_WIDTH, null, false );
            if ( is_wp_error( $resized ) ) {
                @unlink( $tmp );
                return new WP_Error( 'wx_img_resize', '图片压缩失败：' . $resized->get_error_message() );
            }
        }

        $editor->set_quality( self::QUALITY );
        $out_path = $tmp . '-wx.jpg';
        $saved = $editor->save( $out_path, 'image/jpeg' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            @unlink( $tmp );
            return new WP_Error( 'wx_img_save', '图片转 JPG 失败：微信只接受 JPG / PNG / GIF' );
        }

        // uploadimg 硬上限 1MB，900KB 以上再压一档；仍超也照样上传，让微信自己报 40009
        if ( (int) @filesize( $saved['path'] ) > self::MAX_IMAGE_SIZE ) {
            $editor->set_quality( self::QUALITY_LOW );
            $again = $editor->save( $saved['path'], 'image/jpeg' );
            if ( ! is_wp_error( $again ) && ! empty( $again['path'] ) ) {
                $saved = $again;
            }
        }

        $binary = @file_get_contents( $saved['path'] );
        @unlink( $saved['path'] );
        @unlink( $tmp );

        if ( ! is_string( $binary ) || '' === $binary ) {
            return new WP_Error( 'wx_img_read', '压缩后的图片读取失败' );
        }

        $url = YIMAI_GSO_Wx_Api::upload_content_image( $binary, 'wx-' . (int) $index . '.jpg' );
        if ( is_wp_error( $url ) ) {
            return new WP_Error( 'wx_img_upload', $url->get_error_message() );
        }
        return (string) $url;
    }

    /**
     * 图片转存失败时换成一行灰字提示（不中断整篇）
     *
     * wpautop 常把独占一段的图片包成 <p><img></p>，此时若直接塞一个 <p> 进去就是
     * <p><p>…</p></p>，微信的清洗器会把段落拆开、凭空多出两个空行。所以分三种情况：
     *   - 父级 <p> 里只有这张图 → 连父级一起换成提示 <p>（最常见的形态）
     *   - 父级 <p> 里还有文字 → 提示降级成 <span>，保持行内、不破坏段落
     *   - 其它容器（figure / section / 裸正文）→ 按规格用提示 <p>
     *
     * @param DOMDocument $doc
     * @param DOMElement  $img
     * @param string      $alt
     */
    private static function replace_image_with_notice( DOMDocument $doc, DOMElement $img, $alt ) {
        $parent = $img->parentNode;
        if ( ! $parent ) {
            return;
        }
        $label = '' !== $alt ? $alt : '图片';

        $is_p = ( $parent instanceof DOMElement && 'p' === strtolower( $parent->nodeName ) );
        if ( $is_p && self::is_lone_child( $parent, $img ) && $parent->parentNode ) {
            $notice = self::notice_node( $doc, 'p', $label );
            $parent->parentNode->replaceChild( $notice, $parent );
            return;
        }

        $notice = self::notice_node( $doc, $is_p ? 'span' : 'p', $label );
        $parent->replaceChild( $notice, $img );
    }

    /**
     * 构造提示节点
     *
     * @param DOMDocument $doc
     * @param string      $tag
     * @param string      $label
     * @return DOMElement
     */
    private static function notice_node( DOMDocument $doc, $tag, $label ) {
        $notice = $doc->createElement( $tag );
        $notice->setAttribute( 'style', 'color:#94a3b8;font-size:14px' );
        $notice->appendChild( $doc->createTextNode( '[图片未能转存：' . $label . ']' ) );
        return $notice;
    }

    /**
     * 该元素是否只装着这一个子元素（忽略空白文本）
     *
     * @param DOMElement $parent
     * @param DOMNode    $child
     * @return bool
     */
    private static function is_lone_child( DOMElement $parent, DOMNode $child ) {
        foreach ( $parent->childNodes as $node ) {
            if ( $node === $child ) {
                continue;
            }
            if ( XML_ELEMENT_NODE === $node->nodeType ) {
                return false;
            }
            if ( XML_TEXT_NODE === $node->nodeType && '' !== trim( (string) $node->nodeValue ) ) {
                return false;
            }
        }
        return true;
    }

    /* ==================== 8：外链 → span + 脚注 ==================== */

    /**
     * 链接处理
     *
     * 微信正文不支持外链跳转（订阅号连「阅读原文」都只有一个），所以：
     *   - 站内链接：转 span 上色，读者看不出是链接，但也不会出现点不动的假链接
     *   - 站外链接：同样转 span，同时把 URL 收进文末脚注，读者还能手动复制；
     *     并在 span 后面挂一个 [n] 上标，让读者知道这条对应文末第几条
     *   - 没有 href（锚点 / 空链接）：直接 unwrap，留下纯文字
     *
     * 编号只在本次调用内有效（$notes 是局部变量，每次 render 从 1 开始）：
     * 静态属性会跨文章累积，第 3 篇文章的脚注就会从 [7] 起跳。
     *
     * @param DOMDocument $doc
     * @param DOMElement  $root
     */
    private static function process_links( DOMDocument $doc, DOMElement $root ) {
        $anchors = array();
        foreach ( $root->getElementsByTagName( 'a' ) as $a ) {
            $anchors[] = $a;
        }

        $site = self::site_host();
        $notes = array();   // url => array( 'text' => ..., 'no' => n )，同一 url 只编一次号

        foreach ( $anchors as $a ) {
            if ( ! $a->parentNode ) {
                continue;
            }
            $href = trim( (string) $a->getAttribute( 'href' ) );
            $text = trim( (string) $a->textContent );

            if ( '' === $href || '#' === substr( $href, 0, 1 ) ) {
                self::unwrap( $a );
                continue;
            }

            $host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
            // 相对链接没有 host，按站内处理（它本来就只会指向本站）
            $is_internal = ( '' === $host ) || self::same_host( $host, $site );

            $no = 0;
            if ( ! $is_internal ) {
                if ( ! isset( $notes[ $href ] ) ) {
                    $notes[ $href ] = array(
                        'text' => mb_substr( '' !== $text ? $text : $href, 0, 60 ),
                        'no'   => count( $notes ) + 1,
                    );
                }
                $no = (int) $notes[ $href ]['no'];
            }

            $span = self::anchor_to_span( $doc, $a );
            // 角标挂在 span 外面：塞进 span 会继承 #576b95 的链接色，变成一坨色块
            if ( $no > 0 && $span && $span->parentNode ) {
                self::append_note_badge( $doc, $span, $no );
            }
        }

        if ( empty( $notes ) ) {
            return;
        }

        $section = $doc->createElement( 'section' );
        $section->setAttribute( 'style', 'margin-top:2em;padding-top:1em;border-top:1px solid #eee;font-size:13px;color:#94a3b8' );
        foreach ( $notes as $url => $note ) {
            $p = $doc->createElement( 'p' );
            $p->setAttribute( 'style', 'margin:0 0 .4em;font-size:13px;color:#94a3b8;word-break:break-all' );
            // 编号用半角 [n]，与正文角标写法完全一致，读者一眼能对上
            $p->appendChild( $doc->createTextNode( '[' . (int) $note['no'] . ']' . $note['text'] . '：' . $url ) );
            $section->appendChild( $p );
        }
        $root->appendChild( $section );
    }

    /**
     * 在链接 span 后面追加 [n] 上标角标
     *
     * 用 span 而不是 sup：微信剥 class、只认内联样式，这是已验证的机制；但某个标签
     * 会不会被服务端保留属于**标签白名单**问题，本地没有 AppID / 站点也无法验证。
     * 角标掉了不致命，却是「发出去才看得见」的那类问题，所以这里只用在整套渲染器里
     * 已经跑了无数遍的 span，抬起效果交给内联 vertical-align:super。
     *
     * 注入时机在 clean_children 之后，所以角标不受白名单清洗影响（作者自己写的 sup
     * 仍会被 unwrap —— 那是既有口径，本补丁不改白名单）。
     *
     * @param DOMDocument $doc
     * @param DOMElement  $span
     * @param int         $no
     */
    private static function append_note_badge( DOMDocument $doc, DOMElement $span, $no ) {
        $badge = $doc->createElement( 'span' );
        $badge->setAttribute( 'style', 'font-size:12px;color:#576b95;vertical-align:super;margin-left:2px;line-height:0' );
        $badge->appendChild( $doc->createTextNode( '[' . (int) $no . ']' ) );
        if ( $span->nextSibling ) {
            $span->parentNode->insertBefore( $badge, $span->nextSibling );
        } else {
            // 链接在段落末尾时没有下一个兄弟，直接追加
            $span->parentNode->appendChild( $badge );
        }
    }

    /**
     * 把 <a> 换成 <span>，保留子节点顺序与文字
     *
     * @param DOMDocument $doc
     * @param DOMElement  $a
     * @return DOMElement|null 新 span（父级缺失时返回 null）
     */
    private static function anchor_to_span( DOMDocument $doc, DOMElement $a ) {
        $parent = $a->parentNode;
        if ( ! $parent ) {
            return null;
        }
        $span = $doc->createElement( 'span' );
        $span->setAttribute( 'style', 'color:#576b95;text-decoration:none' );
        while ( $a->firstChild ) {
            $span->appendChild( $a->firstChild );
        }
        $parent->replaceChild( $span, $a );
        return $span;
    }

    /**
     * unwrap：子节点整体前移到父级，再删掉自己
     *
     * @param DOMElement $node
     */
    private static function unwrap( DOMElement $node ) {
        $parent = $node->parentNode;
        if ( ! $parent ) {
            return;
        }
        while ( $node->firstChild ) {
            $parent->insertBefore( $node->firstChild, $node );
        }
        $parent->removeChild( $node );
    }

    /**
     * 本站 host（去掉 www. 便于比较）
     *
     * @return string
     */
    private static function site_host() {
        static $cached = null;
        if ( null === $cached ) {
            $cached = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        }
        return $cached;
    }

    /**
     * 只比较注册域级别的同名后缀会让 example.com 与 notexample.com 判等，
     * 所以这里要求「完全相同」或「差一个 www.」。
     *
     * @param string $host
     * @param string $site
     * @return bool
     */
    private static function same_host( $host, $site ) {
        if ( '' === $host || '' === $site ) {
            return false;
        }
        if ( $host === $site ) {
            return true;
        }
        return ( ( 'www.' . $host ) === $site ) || ( ( 'www.' . $site ) === $host );
    }
}
