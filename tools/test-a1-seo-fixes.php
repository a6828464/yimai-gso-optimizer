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
function wp_parse_url( $url, $component = -1 ) {
    return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function apply_filters( $tag, $value ) {
    $args = func_get_args();
    array_shift( $args );
    foreach ( $GLOBALS['__filters'] as $cb ) {
        $value = call_user_func_array( $cb, $args );
    }
    return $value;
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

require_once dirname( __DIR__ ) . '/includes/class-publisher.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-meta-optimizer.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-pusher.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-platform-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-seo-scorer.php';
require_once dirname( __DIR__ ) . '/includes/class-llms-txt-generator.php';

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

/* ---------------- 汇总 ---------------- */

echo "\n合计：{$GLOBALS['__pass']} 通过，{$GLOBALS['__fail']} 失败\n";
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
