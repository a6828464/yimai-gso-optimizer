<?php
/**
 * 正文图片块 · 无图注（开发期用，随 tools/ 不进发布包）
 *
 * 用法：php tools/test-image-figure.php
 *
 * 2026-11-27 口径：正文配图只输出 <figure><img></figure>，
 * 图片下方不得再出现任何解释文字——哪怕调用方传了 caption 也必须忽略。
 */
define( 'ABSPATH', '/tmp/' );
function esc_url( $u ) { return $u; }
function esc_attr( $a ) { return htmlspecialchars( (string) $a, ENT_QUOTES ); }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }

require __DIR__ . '/../includes/class-image-handler.php';

$fail = 0; $n = 0;
function a( $cond, $msg ) { global $fail, $n; $n++; if ( $cond ) { echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

$html = YIMAI_GSO_Image_Handler::figure_html( array(
    'url'     => 'https://www.nbyimai.com/a.jpg',
    'alt'     => '普拉提核心床',
    'caption' => '这是一句不该出现的图注',
) );
a( false !== strpos( $html, '<figure>' ), '输出 figure' );
a( false !== strpos( $html, '<img src="https://www.nbyimai.com/a.jpg"' ), '输出 img' );
a( false === strpos( $html, 'figcaption' ), '不输出 figcaption' );
a( false === strpos( $html, '不该出现' ), '传进来的 caption 被忽略' );

$plain = YIMAI_GSO_Image_Handler::figure_html( array( 'url' => 'https://x/b.jpg' ) );
a( false === strpos( $plain, 'figcaption' ), '无 caption 时也不补 alt 当图注' );

$empty = YIMAI_GSO_Image_Handler::figure_html( array( 'url' => '' ) );
a( '' === $empty, '空 url 返回空串' );

echo "合计 $n 通过, $fail 失败\n";
exit( $fail ? 1 : 0 );
