<?php
/**
 * 一麦GSO 公众号同步 · 离线桩测试（开发期用，随 tools/ 一起不进发布包）
 *
 * 用法：php tools/test-wechat-publisher.php
 *
 * 只验证「判定与拼装」这类不碰微信接口的逻辑：
 *  - 可同步状态口径（publish / draft / pending，其余一律拦下）
 *  - 非已发布文章的「阅读原文」必须留空（?p=ID 对读者是死链）
 *  - get_jobs_map() 一页多篇只查一次、每篇取最新一行
 *  - process() 的状态闸门（草稿放行、私密/回收站/已删除拦下）
 *
 * 为什么用 WP 桩而不是真站：本机没有 WordPress，且这些方法只需要少数 WP 函数；
 * 真正要真机验的是「草稿同步进公众号草稿箱后排版对不对」，见
 * docs/公众号同步-使用指南.md 第二节的真机验证清单。
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ---------------- WordPress 桩 ---------------- */

class WP_Post {
    public $ID = 0;
    public $post_title = '';
    public $post_content = '';
    public $post_status = 'publish';
    public $post_modified_gmt = '2026-01-01 00:00:00';
    public function __construct( $id, $status, $title = '标题', $content = '<p>正文</p>' ) {
        $this->ID = $id; $this->post_status = $status;
        $this->post_title = $title; $this->post_content = $content;
    }
}

class WP_Error {
    private $code; private $msg; private $data;
    public function __construct( $code = '', $msg = '', $data = null ) {
        $this->code = $code; $this->msg = $msg; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; }
    public function get_error_data() { return $this->data; }
}

class Fake_WPDB {
    public $prefix = 'wp_';
    public $rows = array();
    public $queries = array();

    public function prepare( $query, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
            $args = $args[0];
        }
        $i = 0;
        $out = preg_replace_callback( '/%d/', function () use ( &$i, $args ) {
            return (string) (int) $args[ $i++ ];
        }, $query );
        $this->queries[] = $out;
        return $out;
    }

    public function get_results( $sql ) {
        if ( ! preg_match( '/post_id IN \(([^)]*)\)/', $sql, $m ) ) { return array(); }
        $wanted = array_map( 'intval', array_map( 'trim', explode( ',', $m[1] ) ) );
        $out = array();
        foreach ( $this->rows as $row ) {
            if ( in_array( (int) $row->post_id, $wanted, true ) ) { $out[] = $row; }
        }
        return $out;
    }
}

$GLOBALS['wp_posts'] = array();

function absint( $v ) { return abs( (int) $v ); }
function get_post( $id ) {
    $id = (int) $id;
    return isset( $GLOBALS['wp_posts'][ $id ] ) ? $GLOBALS['wp_posts'][ $id ] : null;
}
function get_permalink( $post ) { return 'https://site.test/?p=' . ( is_object( $post ) ? (int) $post->ID : 0 ); }
function get_post_meta( $id, $key, $single = false ) { return ''; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function has_excerpt( $post ) { return false; }
function get_the_excerpt( $post ) { return ''; }
function do_shortcode( $s ) { return $s; }

global $wpdb;
$wpdb = new Fake_WPDB();

/* ---------------- 被测类 ---------------- */

require_once dirname( __DIR__ ) . '/includes/class-wechat-publisher.php';

/* ---------------- 断言 ---------------- */

$pass = 0; $fail = 0;

function check( $label, $actual, $expected ) {
    global $pass, $fail;
    $ok = is_array( $expected )
        ? ( $actual === $expected )
        : ( (string) $actual === (string) $expected );
    if ( $ok ) { $pass++; printf( "  PASS  %s\n", $label ); }
    else {
        $fail++;
        printf( "  FAIL  %s（期望 %s，实际 %s）\n", $label,
            is_array( $expected ) ? json_encode( $expected, JSON_UNESCAPED_UNICODE ) : var_export( $expected, true ),
            is_array( $actual ) ? json_encode( $actual, JSON_UNESCAPED_UNICODE ) : var_export( $actual, true ) );
    }
}

echo "== 1. 可同步状态口径 ==\n";
foreach ( array( 'publish', 'draft', 'pending' ) as $s ) {
    check( "is_syncable_status($s) = true", YIMAI_GSO_Wechat_Publisher::is_syncable_status( $s ), true );
}
foreach ( array( 'private', 'future', 'trash', 'auto-draft', 'inherit', '' ) as $s ) {
    check( "is_syncable_status('$s') = false", YIMAI_GSO_Wechat_Publisher::is_syncable_status( $s ), false );
}
check( 'syncable_statuses() 顺序与内容', YIMAI_GSO_Wechat_Publisher::syncable_statuses(), array( 'publish', 'draft', 'pending' ) );

echo "== 2. 状态文案与徽章 ==\n";
check( 'draft -> 草稿', YIMAI_GSO_Wechat_Publisher::status_label( 'draft' ), '草稿' );
check( 'publish -> 已发布', YIMAI_GSO_Wechat_Publisher::status_label( 'publish' ), '已发布' );
check( 'pending -> 待审', YIMAI_GSO_Wechat_Publisher::status_label( 'pending' ), '待审' );
check( '未知状态原样返回', YIMAI_GSO_Wechat_Publisher::status_label( 'weird-status' ), 'weird-status' );
check( '空状态 -> 未知', YIMAI_GSO_Wechat_Publisher::status_label( '' ), '未知' );
check( 'publish 徽章 is-pass', YIMAI_GSO_Wechat_Publisher::status_badge( 'publish' ), 'is-pass' );
check( 'draft 徽章 is-muted', YIMAI_GSO_Wechat_Publisher::status_badge( 'draft' ), 'is-muted' );
check( 'pending 徽章 is-warn', YIMAI_GSO_Wechat_Publisher::status_badge( 'pending' ), 'is-warn' );
check( 'trash 徽章 is-fail', YIMAI_GSO_Wechat_Publisher::status_badge( 'trash' ), 'is-fail' );

echo "== 3. get_jobs_map：一次查询、每篇取最新一行 ==\n";
$row_a_old = (object) array( 'id' => 4, 'post_id' => 1, 'state' => 'failed' );
$row_a_new = (object) array( 'id' => 5, 'post_id' => 1, 'state' => 'drafted' );
$row_b     = (object) array( 'id' => 3, 'post_id' => 2, 'state' => 'pending' );
$wpdb->rows = array( $row_a_new, $row_a_old, $row_b ); // 模拟 ORDER BY id DESC 的返回顺序
$wpdb->queries = array();

$map = YIMAI_GSO_Wechat_Publisher::get_jobs_map( array( 1, 2, 1, 0 ) );
check( '只发出 1 条查询', count( $wpdb->queries ), 1 );
check( 'SQL 去重且用 IN', ( false !== strpos( $wpdb->queries[0], 'post_id IN (1, 2)' ) ), true );
check( '命中 2 篇', count( $map ), 2 );
check( 'post 1 取最新行 id=5', $map[1]->id, 5 );
check( 'post 2 只有一行 id=3', $map[2]->id, 3 );
check( '空数组不发查询', YIMAI_GSO_Wechat_Publisher::get_jobs_map( array() ), array() );
check( '全 0 不发查询', YIMAI_GSO_Wechat_Publisher::get_jobs_map( array( 0, 0 ) ), array() );

echo "== 4. 「阅读原文」：只有已发布才填地址 ==\n";
$invoke = function ( $method, array $args ) {
    $ref = new ReflectionMethod( 'YIMAI_GSO_Wechat_Publisher', $method );
    // PHP < 8.1 必须显式开放私有方法；8.1+ 起 setAccessible() 已无作用（且 8.5 会报废弃）
    if ( PHP_VERSION_ID < 80100 ) {
        $ref->setAccessible( true );
    }
    return $ref->invokeArgs( null, $args );
};

check( 'publish 有地址', $invoke( 'source_url', array( new WP_Post( 7, 'publish' ) ) ), 'https://site.test/?p=7' );
check( 'draft 留空', $invoke( 'source_url', array( new WP_Post( 8, 'draft' ) ) ), '' );
check( 'pending 留空', $invoke( 'source_url', array( new WP_Post( 9, 'pending' ) ) ), '' );
check( 'null 留空', $invoke( 'source_url', array( null ) ), '' );

$draft_article = $invoke( 'build_article', array( new WP_Post( 11, 'draft', '草稿标题' ), '<p>正文</p>', 'THUMB' ) );
check( 'build_article 草稿 content_source_url 空', $draft_article['content_source_url'], '' );
check( 'build_article 草稿标题', $draft_article['title'], '草稿标题' );
check( 'build_article 封面 media_id', $draft_article['thumb_media_id'], 'THUMB' );

$publish_article = $invoke( 'build_article', array( new WP_Post( 12, 'publish', '已发布标题' ), '<p>正文</p>', 'THUMB' ) );
check( 'build_article 已发布 content_source_url 有地址', $publish_article['content_source_url'], 'https://site.test/?p=12' );

echo "== 5. process() 状态闸门 ==\n";
$GLOBALS['wp_posts'][21] = new WP_Post( 21, 'draft' );
$GLOBALS['wp_posts'][22] = new WP_Post( 22, 'private' );
$GLOBALS['wp_posts'][23] = new WP_Post( 23, 'trash' );
$GLOBALS['wp_posts'][24] = new WP_Post( 24, 'publish' );

$draft_result   = $invoke( 'process', array( (object) array( 'id' => 1, 'post_id' => 21 ) ) );
$private_result = $invoke( 'process', array( (object) array( 'id' => 2, 'post_id' => 22 ) ) );
$trash_result   = $invoke( 'process', array( (object) array( 'id' => 3, 'post_id' => 23 ) ) );
$publish_result = $invoke( 'process', array( (object) array( 'id' => 4, 'post_id' => 24 ) ) );
$missing_result = $invoke( 'process', array( (object) array( 'id' => 5, 'post_id' => 999 ) ) );

// 草稿/已发布在本桩里会继续往下跑到「渲染器未加载」，说明已经过了状态闸门
check( '草稿通过状态闸门', $draft_result->get_error_code(), 'yimai_gso_wx_missing_class' );
check( '已发布通过状态闸门', $publish_result->get_error_code(), 'yimai_gso_wx_missing_class' );
check( '私密被拦下（skipped）', $private_result->get_error_code(), 'wx_skip' );
check( '私密错误文案含状态名', ( false !== strpos( $private_result->get_error_message(), '私密' ) ), true );
check( '回收站被拦下（skipped）', $trash_result->get_error_code(), 'wx_skip' );
check( '已删除文章被拦下（skipped）', $missing_result->get_error_code(), 'wx_skip' );

echo "\n合计：{$pass} 通过，{$fail} 失败\n";
exit( $fail > 0 ? 1 : 0 );
