<?php
/**
 * 一麦GSO A1/A1b 修复 · 离线桩测试（开发期用，tools/ 已被 .gitattributes export-ignore，不进发布包）
 *
 * 用法：php tools/test-a1-seo-fixes.php
 *
 * 只验证「判定与拼装」这类不碰 WordPress 数据库/HTTP 的逻辑：
 *  - 发布时 SEO 标题/摘要落库（yimai_gso_seo_title / yimai_gso_excerpt）
 *  - SEO 描述 78 字限长 + 12 字内标点断句，空值仍回落 post_excerpt
 *  - 百度推送地址一律重建官方格式（site 用纯域名、token 兜底、host 白名单）
 *  - 首页把主题门店节点计入 ai-schema-coverage（主题无门店配置时不声明）
 *  - 评分口径与 78 字描述对齐（68-90 字满分，A1b）
 *  - llms.txt 分类默认关闭 + 一次性迁移幂等；分类 sitemap 关闭时 404（A-收尾）
 *
 * 为什么用 WP 桩而不是真站：本机没有 WordPress，这些方法只需要少数 WP 函数；
 * 真机验的是「前台标题/摘要真的变了」，见 docs/使用教程.md 的发布后检查清单。
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ---------------- WordPress 桩 ---------------- */

$GLOBALS['__meta']    = array();
$GLOBALS['__options'] = array();
$GLOBALS['__filters'] = array();
$GLOBALS['__front']   = true;
$GLOBALS['__theme']   = null;

class WP_Error {
    private $code;
    private $msg;
    public function __construct( $code = '', $msg = '', $data = null ) {
        $this->code = $code;
        $this->msg  = $msg;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $v ) ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function update_post_meta( $post_id, $key, $value ) {
    $GLOBALS['__meta'][ $post_id ][ $key ] = $value;
    return true;
}
function home_url( $path = '' ) { return 'https://www.nbyimai.com' . $path; }
function site_url( $path = '' ) { return 'https://www.nbyimai.com' . $path; }
function get_bloginfo( $key = 'name' ) { return '一麦瑜伽'; }
function wp_parse_url( $url, $component = -1 ) {
    return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function apply_filters( $tag, $value ) {
    $args = func_get_args();
    array_shift( $args );
    if ( empty( $GLOBALS['__filters'][ $tag ] ) ) {
        return $value;
    }
    foreach ( $GLOBALS['__filters'][ $tag ] as $cb ) {
        $value = call_user_func_array( $cb, $args );
    }
    return $value;
}
function add_filter_stub( $tag, $cb ) {
    $GLOBALS['__filters'][ $tag ][] = $cb;
}
function is_front_page() { return (bool) $GLOBALS['__front']; }
function yimai_config( $invalidate = false ) { return $GLOBALS['__theme']; }

/* option 层（llms 设置 / 迁移标记用，键为完整 option 名） */
$GLOBALS['__wp_options'] = array();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['__wp_options'] ) ? $GLOBALS['__wp_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['__wp_options'][ $name ] = $value;
    return true;
}
function delete_transient( $key ) { return true; }
function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( (array) $defaults, (array) $args );
}

class YIMAI_GSO_Settings {
    public static function get( $key, $default = '' ) {
        return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default;
    }
}

/* 文章 / meta / 附件层（批量换图用） */
$GLOBALS['__posts']   = array();
$GLOBALS['__updates'] = 0;

function make_post_fixture( $id, $content, $title = '宁波普拉提小班课怎么选', $excerpt = '摘要' ) {
    $GLOBALS['__posts'][ $id ] = (object) array(
        'ID'           => $id,
        'post_type'    => 'post',
        'post_title'   => $title,
        'post_content' => $content,
        'post_excerpt' => $excerpt,
    );
    if ( ! isset( $GLOBALS['__meta'][ $id ] ) ) {
        $GLOBALS['__meta'][ $id ] = array();
    }
    return $GLOBALS['__posts'][ $id ];
}

function get_post( $post = null ) {
    $id = is_object( $post ) ? (int) $post->ID : (int) $post;
    return $GLOBALS['__posts'][ $id ] ?? null;
}
function get_post_field( $field, $post_id ) {
    $post = $GLOBALS['__posts'][ (int) $post_id ] ?? null;
    return $post ? ( $post->$field ?? '' ) : '';
}
function wp_update_post( $data, $wp_error = false ) {
    $GLOBALS['__updates']++;
    $id = (int) ( $data['ID'] ?? 0 );
    if ( $id && isset( $GLOBALS['__posts'][ $id ] ) && isset( $data['post_content'] ) ) {
        $GLOBALS['__posts'][ $id ]->post_content = $data['post_content'];
    }
    return $id;
}
function get_post_meta( $post_id, $key = '', $single = false ) {
    $all = $GLOBALS['__meta'][ (int) $post_id ] ?? array();
    if ( '' === $key ) {
        return $all;
    }
    $value = $all[ $key ] ?? '';
    return $single ? $value : array( $value );
}
function delete_post_meta( $post_id, $key ) {
    unset( $GLOBALS['__meta'][ (int) $post_id ][ $key ] );
    return true;
}
function get_posts( $args = array() ) {
    $ids = array_keys( $GLOBALS['__posts'] );
    sort( $ids );
    return $ids;
}
function get_the_category( $post_id ) {
    return array( (object) array( 'name' => '瑜伽入门', 'term_id' => 7 ) );
}
function current_time( $format = 'mysql' ) { return '2026-10-31 04:00:00'; }
function wp_slash( $value ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_upload_dir() {
    return array( 'baseurl' => 'https://www.nbyimai.com/wp-content/uploads' );
}
function wp_get_attachment_url( $id ) {
    return 'https://www.nbyimai.com/wp-content/uploads/mt-' . (int) $id . '.jpg';
}
function wp_get_attachment_metadata( $id ) {
    return array( 'width' => 1200, 'height' => 800 );
}
function esc_url( $value ) { return (string) $value; }
function get_post_thumbnail_id( $post_id ) { return (int) ( $GLOBALS['__thumbs'][ (int) $post_id ] ?? 0 ); }
function set_post_thumbnail( $post_id, $thumb_id ) { $GLOBALS['__thumbs'][ (int) $post_id ] = (int) $thumb_id; return true; }
function delete_post_thumbnail( $post_id ) { unset( $GLOBALS['__thumbs'][ (int) $post_id ] ); return true; }
function attachment_url_to_postid( $url ) { return 0; }
function get_attached_file( $attachment_id ) { return ''; }

/* 图片入库替身：真实入库逻辑在 Image_Handler，测试里用过滤器替身（见第 10 组） */
class YIMAI_GSO_Image_Handler {
    public function upload_image_to_media( $url, $filename = '', $alt = '', $timeout = 60 ) {
        return 9000;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-publisher.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-meta-optimizer.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-pusher.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-platform-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-scorer.php';
require_once dirname( __DIR__ ) . '/includes/class-llms-txt-generator.php';
require_once dirname( __DIR__ ) . '/includes/class-batch-reimage.php';

/* ---------------- 断言小工具 ---------------- */

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check( $label, $cond, $detail = '' ) {
    if ( $cond ) {
        $GLOBALS['__pass']++;
        echo "  PASS  {$label}\n";
        return;
    }
    $GLOBALS['__fail']++;
    echo "  FAIL  {$label}" . ( '' !== $detail ? " → {$detail}" : '' ) . "\n";
}

function call_private( $class, $method, $args = array(), $object = null ) {
    $ref = new ReflectionMethod( $class, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $object, $args );
}

function wp_json_encode_stub( $data ) { return json_encode( $data, JSON_UNESCAPED_UNICODE ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }

function len( $text ) {
    return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
}

/* ---------------- 1. 发布时标题 / 摘要落库 ---------------- */

echo "== 1. write_seo_fields：标题与摘要落库 ==\n";

YIMAI_GSO_Publisher::write_seo_fields( 101, '宁波瑜伽,普拉提', '宁波鄞州印象城门店，预约体验普拉提小班课。', '宁波普拉提小班课｜一麦瑜伽' );
check( '写 yimai_gso_seo_title', '宁波普拉提小班课｜一麦瑜伽' === ( $GLOBALS['__meta'][101]['yimai_gso_seo_title'] ?? '' ) );
check( '写 yimai_gso_excerpt', '宁波鄞州印象城门店，预约体验普拉提小班课。' === ( $GLOBALS['__meta'][101]['yimai_gso_excerpt'] ?? '' ) );
check( 'Yoast 标题仍同步', '宁波普拉提小班课｜一麦瑜伽' === ( $GLOBALS['__meta'][101]['_yoast_wpseo_title'] ?? '' ) );
check( '富文本关键词仍同步', '宁波瑜伽,普拉提' === ( $GLOBALS['__meta'][101]['keywords'] ?? '' ) );

YIMAI_GSO_Publisher::write_seo_fields( 102, '关键词', '', '' );
check( '标题为空不写 yimai_gso_seo_title', ! isset( $GLOBALS['__meta'][102]['yimai_gso_seo_title'] ) );
check( '摘要为空不写 yimai_gso_excerpt', ! isset( $GLOBALS['__meta'][102]['yimai_gso_excerpt'] ) );

/* ---------------- 2. 描述限长与断句 ---------------- */

echo "== 2. truncate_description：78 字 + 12 字内标点断句 ==\n";

$sentence = '宁波鄞州普拉提小班课怎么选？先看教练资质与器械数量，再约一节体验课感受节奏。';
$long     = str_repeat( $sentence, 4 );
$cut      = call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'truncate_description', array( $long, 78 ) );
check( '长描述被截到 ≤ 78 字', len( $cut ) <= 78, (string) len( $cut ) );
check( '截断处落在标点上', in_array( mb_substr( $cut, -1 ), array( '。', '！', '？', '；', '，' ), true ), $cut );

$no_punct = str_repeat( '宁波普拉提小班课', 12 );
$hard     = call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'truncate_description', array( $no_punct, 78 ) );
check( '无标点时硬截 78 字', 78 === len( $hard ), (string) len( $hard ) );
check( '无标点结果无尾随空格', $hard === trim( $hard ) );

$short = '宁波瑜伽馆体验课怎么约？';
check( '短描述原样返回', $short === call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'truncate_description', array( $short, 78 ) ) );
check( '空描述返回空串', '' === call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'truncate_description', array( "  \n ", 78 ) ) );

/* ---------------- 3. 空值回落 post_excerpt ---------------- */

echo "== 3. normalize_suggested_fields：空描述回落 post_excerpt ==\n";

$post               = new stdClass();
$post->post_title   = '宁波普拉提';
$post->post_excerpt = str_repeat( '宁波普拉提小班课预约体验，', 8 );
$post->post_content = '<p>正文</p>';

$norm = call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'normalize_suggested_fields', array( array( 'seo_description' => '' ), $post ) );
check( '回落 post_excerpt 内容', false !== strpos( $norm['description'], '宁波普拉提小班课预约体验' ), $norm['description'] );
check( '回落结果 ≤ 78 字', len( $norm['description'] ) <= 78, (string) len( $norm['description'] ) );

$norm2 = call_private( 'YIMAI_GSO_SEO_Meta_Optimizer', 'normalize_suggested_fields', array( array( 'seo_description' => $long ), $post ) );
check( 'AI 超长描述被限到 ≤ 78 字', len( $norm2['description'] ) <= 78, (string) len( $norm2['description'] ) );

/* ---------------- 4. 百度推送地址重建 ---------------- */

echo "== 4. get_baidu_api_url：一律重建官方格式 ==\n";

$pusher = ( new ReflectionClass( 'YIMAI_GSO_SEO_Pusher' ) )->newInstanceWithoutConstructor();

$GLOBALS['__options']['seo_push_baidu_api_address'] = 'https://data.zz.baidu.com/urls?site=https://www.nbyimai.com&token=abc123';
$url = call_private( 'YIMAI_GSO_SEO_Pusher', 'get_baidu_api_url', array(), $pusher );
check( '非法 site 重建成纯域名', 'http://data.zz.baidu.com/urls?site=www.nbyimai.com&token=abc123' === $url, is_string( $url ) ? $url : get_class( $url ) );

$GLOBALS['__options']['seo_push_baidu_api_address'] = 'http://www.baidu.com/urls?type=original&site=www.nbyimai.com';
$GLOBALS['__options']['seo_push_baidu_token']       = 'tk_from_setting';
$url = call_private( 'YIMAI_GSO_SEO_Pusher', 'get_baidu_api_url', array(), $pusher );
check( '地址缺 token 时回落设置 token', 'http://data.zz.baidu.com/urls?site=www.nbyimai.com&token=tk_from_setting' === $url, is_string( $url ) ? $url : get_class( $url ) );

$GLOBALS['__options']['seo_push_baidu_api_address'] = 'https://evil.example.com/urls?token=x';
$res = call_private( 'YIMAI_GSO_SEO_Pusher', 'get_baidu_api_url', array(), $pusher );
check( '非白名单 host 返回 WP_Error', is_wp_error( $res ) && 'baidu_invalid_url' === $res->get_error_code() );

$GLOBALS['__options']['seo_push_baidu_api_address'] = '';
$GLOBALS['__options']['seo_push_baidu_token']       = 'only_token';
$url = call_private( 'YIMAI_GSO_SEO_Pusher', 'get_baidu_api_url', array(), $pusher );
check( '无地址时用设置 token 拼接', 'http://data.zz.baidu.com/urls?site=www.nbyimai.com&token=only_token' === $url, is_string( $url ) ? $url : get_class( $url ) );

$GLOBALS['__options']['seo_push_baidu_token'] = '';
$res = call_private( 'YIMAI_GSO_SEO_Pusher', 'get_baidu_api_url', array(), $pusher );
check( '无 token 返回 WP_Error', is_wp_error( $res ) && 'baidu_no_config' === $res->get_error_code() );

/* ---------------- 5. 首页主题门店类型 ---------------- */

echo "== 5. get_theme_local_business_types：首页门店节点 ==\n";

$adapter = ( new ReflectionClass( 'YIMAI_GSO_AI_Platform_Adapter' ) )->newInstanceWithoutConstructor();

$GLOBALS['__front'] = false;
check( '非首页返回空数组', array() === call_private( 'YIMAI_GSO_AI_Platform_Adapter', 'get_theme_local_business_types', array(), $adapter ) );

$GLOBALS['__front'] = true;
$GLOBALS['__theme'] = array( 'studios' => array( array( 'name' => '印象城店' ), array( 'name' => '来福士店' ) ) );
$types = call_private( 'YIMAI_GSO_AI_Platform_Adapter', 'get_theme_local_business_types', array(), $adapter );
check( '首页有门店 → 含 HealthAndBeautyBusiness', in_array( 'HealthAndBeautyBusiness', $types, true ), implode( ',', $types ) );
check( '首页有门店 → 含 LocalBusiness', in_array( 'LocalBusiness', $types, true ), implode( ',', $types ) );
check( '类型已去重', count( $types ) === count( array_unique( $types ) ) );

$GLOBALS['__theme'] = array( 'studios' => array() );
check( '主题无门店配置时不声明', array() === call_private( 'YIMAI_GSO_AI_Platform_Adapter', 'get_theme_local_business_types', array(), $adapter ) );

$GLOBALS['__theme'] = null;
check( '主题配置为空时不声明', array() === call_private( 'YIMAI_GSO_AI_Platform_Adapter', 'get_theme_local_business_types', array(), $adapter ) );

/* ---------------- 6. 评分口径：68-90 字满分 ---------------- */

echo "== 6. score_meta_description：68-90 字满分（A1b）==\n";

function desc_score( $scorer, $len ) {
    $result = call_private( 'YIMAI_GSO_SEO_Scorer', 'score_meta_description', array( str_repeat( '测', $len ), '', '' ), $scorer );
    return (int) $result['score'];
}

$scorer = ( new ReflectionClass( 'YIMAI_GSO_SEO_Scorer' ) )->newInstanceWithoutConstructor();

// 关键词为空、标题为空时：长度分 + 「描述与标题无法比对」25 分 = 长度分 + 25
check( '68 字满分（65）', 65 === desc_score( $scorer, 68 ), (string) desc_score( $scorer, 68 ) );
check( '78 字满分（65）', 65 === desc_score( $scorer, 78 ), (string) desc_score( $scorer, 78 ) );
check( '90 字仍满分（65）', 65 === desc_score( $scorer, 90 ), (string) desc_score( $scorer, 90 ) );
check( '95 字开始递减（63）', 63 === desc_score( $scorer, 95 ), (string) desc_score( $scorer, 95 ) );
check( '160 字降到 40', 40 === desc_score( $scorer, 160 ), (string) desc_score( $scorer, 160 ) );
check( '200 字低分（35）', 35 === desc_score( $scorer, 200 ), (string) desc_score( $scorer, 200 ) );
check( '67 字偏短（50）', 50 === desc_score( $scorer, 67 ), (string) desc_score( $scorer, 67 ) );
check( '40 字过短（35）', 35 === desc_score( $scorer, 40 ), (string) desc_score( $scorer, 40 ) );

$over = call_private( 'YIMAI_GSO_SEO_Scorer', 'score_meta_description', array( str_repeat( '测', 95 ), '', '' ), $scorer );
check( '超长提示回到 68-90 口径', false !== strpos( implode( ' ', $over['tips'] ), '68-90' ), implode( ' ', $over['tips'] ) );

/* ---------------- 7. llms.txt 分类默认值与一次性迁移（A-收尾） ---------------- */

echo "== 7. llms.txt：include_categories 默认 0 ==\n";

$llms = ( new ReflectionClass( 'YIMAI_GSO_LLMs_Txt_Generator' ) )->newInstanceWithoutConstructor();

$GLOBALS['__wp_options'] = array();
$llms_settings = $llms->get_settings();
check( '默认 include_categories = 0', 0 === (int) $llms_settings['include_categories'], var_export( $llms_settings['include_categories'], true ) );

echo "== 8. llms.txt：旧值迁移只跑一次 ==\n";

$GLOBALS['__wp_options'] = array(
    'yimai_gso_llms_txt_settings' => array( 'include_categories' => 1, 'intro' => '一麦瑜伽' ),
);
$llms->maybe_migrate_categories_default();
$migrated = $GLOBALS['__wp_options']['yimai_gso_llms_txt_settings'];
check( '旧值 1 迁移为 0', 0 === (int) $migrated['include_categories'], var_export( $migrated['include_categories'], true ) );
check( '写入迁移标记', '1' === (string) ( $GLOBALS['__wp_options']['yimai_gso_llms_categories_migrated'] ?? '' ) );
check( '迁移不清空其它设置', '一麦瑜伽' === ( $migrated['intro'] ?? '' ) );

// 用户日后手动勾回「包含分类目录」：迁移不得再覆盖
$GLOBALS['__wp_options']['yimai_gso_llms_txt_settings']['include_categories'] = 1;
$llms->maybe_migrate_categories_default();
check( '迁移只跑一次（手动开启不被覆盖）', 1 === (int) $GLOBALS['__wp_options']['yimai_gso_llms_txt_settings']['include_categories'] );

/* ---------------- 9. taxonomy sitemap 关闭时 404 ---------------- */

echo "== 9. taxonomy sitemap：关闭时 404、开启时维持 ==\n";

// serve_sitemap() 里是 status_header(404); exit; —— 同进程测不了 exit，改用子进程跑真实代码
$probe_file = sys_get_temp_dir() . '/gso-sitemap-probe-' . getmypid() . '.php';
$class_file = dirname( __DIR__ ) . '/includes/class-sitemap-generator.php';

$probe_code = <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['__enabled'] = ( '1' === ( $argv[1] ?? '0' ) );

class YIMAI_GSO_Settings {
    public static function get( $key, $default = '' ) {
        return 'sitemap_taxonomy_enabled' === $key ? ( $GLOBALS['__enabled'] ? 1 : 0 ) : $default;
    }
}
function sanitize_text_field( $v ) { return (string) $v; }
function status_header( $code ) { echo 'STATUS:' . $code . "\n"; }
function nocache_headers() {}
function taxonomy_exists( $taxonomy ) { return true; }
function get_terms( $args ) { return array(); }
function esc_url( $url ) { return $url; }
function esc_html( $value ) { return $value; }
function home_url( $path = '' ) { return 'https://www.nbyimai.com' . $path; }
class WP_Error {}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_term_link( $term ) { return ''; }

require_once __CLASS_FILE__;

$ref       = new ReflectionClass( 'YIMAI_GSO_Sitemap_Generator' );
$generator = $ref->newInstanceWithoutConstructor();
$wp_query  = new stdClass();
$wp_query->query_vars = array( 'yimai_gso_sitemap_taxonomy' => 'category' );
$generator->serve_sitemap();
echo 'NO-EXIT';
PHP;

$probe_code = str_replace( '__CLASS_FILE__', var_export( $class_file, true ), $probe_code );
file_put_contents( $probe_file, $probe_code );

$run_probe = function ( $enabled ) use ( $probe_file ) {
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe_file ) . ' ' . ( $enabled ? '1' : '0' ) . ' 2>&1';
    $lines   = array();
    $code    = 0;
    exec( $command, $lines, $code );
    return implode( "\n", $lines );
};

$off_output = $run_probe( false );
check( '关闭时返回 404', false !== strpos( $off_output, 'STATUS:404' ), $off_output );
check( '关闭时不输出 urlset', false === strpos( $off_output, '<urlset' ), $off_output );

$on_output = $run_probe( true );
check( '开启时输出 urlset', false !== strpos( $on_output, '<urlset' ), $on_output );
check( '开启时不返回 404', false === strpos( $on_output, 'STATUS:404' ), $on_output );

@unlink( $probe_file );

/* ---------------- 10. 批量换图（A-收尾） ---------------- */

echo "== 10. 批量换图：dry-run / 幂等 / 失败保持 / alt / 回滚 ==\n";

// 取图与入库用过滤器替身：不联网、不写媒体库
$GLOBALS['__fetch_mode'] = 'ok';
add_filter_stub( 'yimai_gso_reimage_fetch_images', function ( $value, $keyword, $count, $context ) {
    if ( 'error' === $GLOBALS['__fetch_mode'] ) {
        return new WP_Error( 'mtphotos_no_folder', 'MT Photos 中未找到带水印的素材文件夹' );
    }
    $out = array();
    for ( $i = 1; $i <= (int) $count; $i++ ) {
        $out[] = array(
            'url'    => 'https://photos1.yimaiyoga.com/gateway/file/' . ( 8000 + $i ) . '?auth_code=x',
            'alt'    => $keyword,
            'source' => 'MT Photos · 0905芳香瑜伽_PixCake_有水印',
        );
    }
    if ( ! empty( $GLOBALS['__fetch_dupe'] ) && ! empty( $out ) ) {
        $out[] = $out[0];
    }
    return $out;
} );
add_filter_stub( 'yimai_gso_reimage_upload_image', function ( $value, $url, $alt ) {
    static $next = 9000;
    return ++$next;
} );

check( 'pexels 判为外部图', YIMAI_GSO_Batch_Reimage::is_external_src( 'https://images.pexels.com/photos/1/a.jpeg' ) );
check( '本站 uploads 判为内部图', ! YIMAI_GSO_Batch_Reimage::is_external_src( 'https://www.nbyimai.com/wp-content/uploads/a.jpg' ) );
check( '相对路径不算外部图', ! YIMAI_GSO_Batch_Reimage::is_external_src( '/wp-content/uploads/a.jpg' ) );

$kw_post = new stdClass();
$kw_post->ID = 900;
$kw_post->post_title = '宁波瑜伽新手怎么选课';
$GLOBALS['__meta'][900] = array( 'yimai_gso_keywords' => '一麦瑜伽,宁波瑜伽新手选课,瑜伽课程怎么选' );
$kw_candidates = YIMAI_GSO_Batch_Reimage::build_keyword_candidates( $kw_post );
check( '关键词候选优先活动词', '瑜伽' === ( $kw_candidates[0] ?? '' ), json_encode( $kw_candidates, JSON_UNESCAPED_UNICODE ) );
check( '品牌词不进候选', ! in_array( '一麦瑜伽', $kw_candidates, true ), json_encode( $kw_candidates, JSON_UNESCAPED_UNICODE ) );
check( '活动词识别（普拉提）', array( '普拉提' ) === YIMAI_GSO_Batch_Reimage::detect_activity_terms( '普拉提大器械体验' ) );

$orig501 = '<p>正文</p><p><img src="https://images.pexels.com/photos/31509822/pexels-photo-31509822.jpeg?auto=compress&amp;cs=tinysrgb&amp;w=940" alt="宁波瑜伽馆实拍" class="aligncenter" /></p>'
    . '<p><img src="https://images.pexels.com/photos/33360222/pexels-photo-33360222.jpeg?auto=compress&amp;w=940" class="wp-image-9"></p>';
make_post_fixture( 501, $orig501 );
update_post_meta( 501, 'yimai_gso_citations', array( 'count' => 3 ) );
update_post_meta( 501, 'yimai_gso_platform_adapt', '{"citation_count":3}' );

$updates_before = $GLOBALS['__updates'];
$dry            = YIMAI_GSO_Batch_Reimage::reimage_post( 501, array( 'dry_run' => true ) );
check( 'dry-run 算出 2 张且命中文件夹', 'ready' === ( $dry['status'] ?? '' ) && 2 === (int) ( $dry['available'] ?? 0 ) && ! empty( $dry['folders'] ), wp_json_encode_stub( $dry ) );
check( 'dry-run 不写文章', $updates_before === $GLOBALS['__updates'] );
check( 'dry-run 不写换图标记', '' === get_post_meta( 501, 'yimai_gso_reimage_at', true ) );

$live    = YIMAI_GSO_Batch_Reimage::reimage_post( 501 );
$content = get_post( 501 )->post_content;
check( '真跑替换 2 张', 'done' === ( $live['status'] ?? '' ) && 2 === count( $live['replaced'] ), wp_json_encode_stub( $live ) );
check( '正文已无 pexels', false === stripos( $content, 'pexels' ) );
check( '图片全部指向本站 uploads', 2 === preg_match_all( '#https://www\.nbyimai\.com/wp-content/uploads/mt-\d+\.jpg#', $content ) );
check( '原 alt 保留', false !== strpos( $content, 'alt="宁波瑜伽馆实拍"' ) );
check( '空 alt 回落到文章标题', false !== strpos( $content, 'alt="宁波普拉提小班课怎么选"' ) );
check( '原 class 保留', false !== strpos( $content, 'class="aligncenter"' ) && false !== strpos( $content, 'class="wp-image-9"' ) );
check( '补了 width/height', false !== strpos( $content, 'width="1200"' ) && false !== strpos( $content, 'height="800"' ) );
check( '写换图标记', 'mtphotos' === get_post_meta( 501, 'yimai_gso_reimage_source', true ) && '' !== get_post_meta( 501, 'yimai_gso_reimage_at', true ) );
check( '清掉旧结构缓存（citation/platform_adapt）', '' === get_post_meta( 501, 'yimai_gso_citations', true ) && '' === get_post_meta( 501, 'yimai_gso_platform_adapt', true ) );
check( '落替换审计 log（数组，含 2 张）', is_array( get_post_meta( 501, 'yimai_gso_reimage_log', true ) ) && 2 === count( get_post_meta( 501, 'yimai_gso_reimage_log', true ) ) );

$backup_keys = array_filter( array_keys( $GLOBALS['__meta'][501] ?? array() ), function ( $k ) {
    return 0 === strpos( $k, 'yimai_gso_content_backup_' );
} );
check( '正文备份只保留一份', 1 === count( $backup_keys ), implode( ',', $backup_keys ) );

make_post_fixture( 502, '<p><img src="https://www.nbyimai.com/wp-content/uploads/a.jpg" alt="a"></p>' );
update_post_meta( 502, 'yimai_gso_reimage_at', '2026-10-31 04:00:00' );
$updates_before = $GLOBALS['__updates'];
$skip           = YIMAI_GSO_Batch_Reimage::reimage_post( 502 );
check( '已换过且无外部图 → 跳过', 'skipped' === ( $skip['status'] ?? '' ) && 'already_done' === ( $skip['reason'] ?? '' ), wp_json_encode_stub( $skip ) );
check( '跳过时不写文章', $updates_before === $GLOBALS['__updates'] );

$orig503 = '<p><img src="https://images.pexels.com/photos/18499500/pexels-photo-18499500.png?w=940" alt="x"></p>';
make_post_fixture( 503, $orig503 );
$GLOBALS['__fetch_mode'] = 'error';
$updates_before = $GLOBALS['__updates'];
$fail           = YIMAI_GSO_Batch_Reimage::reimage_post( 503 );
$GLOBALS['__fetch_mode'] = 'ok';
check( '取图失败 → 跳过并写明原因', 'skipped' === ( $fail['status'] ?? '' ) && 0 === strpos( (string) ( $fail['reason'] ?? '' ), 'no_source' ), (string) ( $fail['reason'] ?? '' ) );
check( '取图失败保持原图不动', $orig503 === get_post( 503 )->post_content && $updates_before === $GLOBALS['__updates'] );
check( '取图失败不写换图标记', '' === get_post_meta( 503, 'yimai_gso_reimage_at', true ) );

$rb = YIMAI_GSO_Batch_Reimage::rollback_post( 501 );
check( '回滚恢复原文', ! is_wp_error( $rb ) && $orig501 === get_post( 501 )->post_content );
check( '回滚清除换图标记', '' === get_post_meta( 501, 'yimai_gso_reimage_at', true ) && '' === get_post_meta( 501, 'yimai_gso_reimage_source', true ) );
check( '回滚清掉审计 log', '' === get_post_meta( 501, 'yimai_gso_reimage_log', true ) || empty( get_post_meta( 501, 'yimai_gso_reimage_log', true ) ) );
check( '回滚后备份被消费', 0 === count( array_filter( array_keys( $GLOBALS['__meta'][501] ?? array() ), function ( $k ) {
    return 0 === strpos( $k, 'yimai_gso_content_backup_' );
} ) ) );

/* ---------------- 11. 特色图 / 无图补图（t13） ---------------- */

echo "== 11. 特色图替换与无图补图 ==\n";

$p6 = '';
for ( $i = 1; $i <= 6; $i++ ) { $p6 .= "<p>第{$i}段：宁波瑜伽馆的练习建议。</p>\n"; }
make_post_fixture( 601, $p6, '宁波瑜伽新手入门指南' );

$p2 = "<p>第一段</p>\n<p>第二段</p>\n";
make_post_fixture( 602, $p2, '宁波普拉提体验课' );

make_post_fixture( 603, '<p>已有图</p><p><img src="https://www.nbyimai.com/wp-content/uploads/a.jpg" alt="a"></p>', '已有图文章' );

$pexels_one = '<p>正文</p><p><img src="https://images.pexels.com/photos/31509822/a.jpeg?w=940" alt="图"></p>';
make_post_fixture( 604, $pexels_one, '特色图判定不了的文章' );
$GLOBALS['__thumbs'][604] = 6101;
$GLOBALS['__meta'][6101]  = array( '_wp_attached_file' => '2026/08/ai-image-unknown.jpg', '_yimai_gso_featured_source' => 'unknown' );

make_post_fixture( 605, $pexels_one, '特色图确定 pexels 的文章' );
$GLOBALS['__thumbs'][605] = 6102;
$GLOBALS['__meta'][6102]  = array( '_wp_attached_file' => '2026/08/ai-image-pexels.jpg', '_yimai_gso_featured_source' => 'pexels' );

check( '特色图判定：无缩略图 → none', 'none' === YIMAI_GSO_Batch_Reimage::featured_status( 601 )['status'] );
check( '特色图判定：meta 标记 pexels', 'pexels' === YIMAI_GSO_Batch_Reimage::featured_status( 605 )['status'] );
check( '特色图判定：标记 unknown 不瞎猜', 'unknown' === YIMAI_GSO_Batch_Reimage::featured_status( 604 )['status'] );

$unknown = YIMAI_GSO_Batch_Reimage::reimage_post( 604, array( 'featured' => true ) );
check( '判定不了不换（缩略图不变）', 6101 === (int) get_post_thumbnail_id( 604 ) && false !== strpos( (string) ( $unknown['featured_reason'] ?? '' ), '判定不了' ), (string) ( $unknown['featured_reason'] ?? '' ) );

$feat = YIMAI_GSO_Batch_Reimage::reimage_post( 605, array( 'featured' => true ) );
$new_thumb = (int) get_post_thumbnail_id( 605 );
$flog = get_post_meta( 605, 'yimai_gso_reimage_featured_log', true );
check( '特色图已替换', 'done' === ( $feat['status'] ?? '' ) && $new_thumb > 0 && 6102 !== $new_thumb );
check( '特色图审计记旧→新', is_array( $flog ) && 6102 === (int) ( $flog[0]['old_thumbnail_id'] ?? 0 ) && $new_thumb === (int) ( $flog[0]['new_thumbnail_id'] ?? 0 ) );

$rb605 = YIMAI_GSO_Batch_Reimage::rollback_post( 605 );
check( '回滚恢复旧特色图', ! is_wp_error( $rb605 ) && 6102 === (int) get_post_thumbnail_id( 605 ) );
check( '回滚恢复原文（特色图篇）', $pexels_one === get_post( 605 )->post_content && '' === get_post_meta( 605, 'yimai_gso_reimage_featured_log', true ) );

$app = YIMAI_GSO_Batch_Reimage::append_images_post( 601 );
check( '无图文章补 3 张（6 段）', 'done' === ( $app['status'] ?? '' ) && 3 === count( $app['inserted'] ?? array() ), wp_json_encode_stub( $app['inserted'] ?? array() ) );
$positions = array_map( function ( $r ) { return (int) $r['after_paragraph']; }, $app['inserted'] );
check( '插入位置按段落分布（非全堆文末）', $positions === array_values( array_unique( $positions ) ) && min( $positions ) >= 1 && max( $positions ) < 6, implode( ',', $positions ) );
check( '补图 alt 非空且带标题', '' !== trim( (string) ( $app['inserted'][0]['alt'] ?? '' ) ) && false !== strpos( $app['inserted'][0]['alt'], '宁波瑜伽新手入门指南' ) );
$urls = array_map( function ( $r ) { return $r['url']; }, $app['inserted'] );
check( '同篇补图不重复文件', count( $urls ) === count( array_unique( $urls ) ) );

$app2 = YIMAI_GSO_Batch_Reimage::append_images_post( 602 );
check( '不足 3 段最多补 1 张', 'done' === ( $app2['status'] ?? '' ) && 1 === count( $app2['inserted'] ?? array() ) );

$skip = YIMAI_GSO_Batch_Reimage::append_images_post( 603 );
check( '已有图不补图', 'has_images' === ( $skip['reason'] ?? '' ) );

// 取图返回重复 URL 时也要去重
$GLOBALS['__fetch_dupe'] = true;
$p3 = "<p>a</p>\n<p>b</p>\n<p>c</p>\n";
make_post_fixture( 606, $p3, '重复候选去重测试' );
$app3 = YIMAI_GSO_Batch_Reimage::append_images_post( 606 );
$GLOBALS['__fetch_dupe'] = false;
$urls3 = array_map( function ( $r ) { return $r['url']; }, $app3['inserted'] ?? array() );
check( '候选重复时去重', count( $urls3 ) === count( array_unique( $urls3 ) ) && count( $urls3 ) >= 1 );

$rb602 = YIMAI_GSO_Batch_Reimage::rollback_post( 602 );
check( '回滚恢复补图前正文', ! is_wp_error( $rb602 ) && $p2 === get_post( 602 )->post_content );

/* ---------------- 汇总 ---------------- */

echo "\n合计：{$GLOBALS['__pass']} 通过，{$GLOBALS['__fail']} 失败\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
