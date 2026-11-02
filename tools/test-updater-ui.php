<?php
/**
 * 在线更新面板 · 静态自检（开发期用，随 tools/ 不进发布包）
 *
 * 用法：php tools/test-updater-ui.php
 *
 * 锁的是「按钮永远在，只是能不能点」这条口径：以前按钮默认 display:none，
 * 检查结果是「已最新」或检查失败时整个入口消失，用户会以为没做更新功能。
 */
$root = is_file( __DIR__ . '/../includes/class-admin.php' ) ? __DIR__ . '/..' : __DIR__;
$admin = file_get_contents( $root . '/includes/class-admin.php' );
$js    = file_get_contents( $root . '/admin/js/updater.js' );
$fail  = 0; $n = 0;
function a( $cond, $msg ) { global $fail, $n; $n++; if ( $cond ) { echo "  PASS $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

a( false === strpos( $admin, 'id="yimai-gso-updater-run" style="display:none"' ), '按钮不再默认 display:none' );
$run_idx = strpos( $admin, 'id="yimai-gso-updater-run"' );
a( false !== $run_idx && false !== strpos( substr( $admin, $run_idx, 120 ), 'disabled' ), '按钮初始 disabled（先检查才能点）' );
a( false === strpos( substr( $admin, $run_idx, 120 ), 'display:none' ), '按钮标签上没有 display:none' );
a( false !== strpos( $admin, '先点「检查更新」' ), '禁用态有 title 说明' );
a( false === strpos( $js, "updater-run').hide()" ), 'JS 不再 hide 按钮' );
a( false !== strpos( $js, "prop('disabled', false)" ), '有新版时解禁' );
a( false !== strpos( $js, '已是最新版本 v' ), '已最新时给出禁用理由' );
a( false !== strpos( $js, "data('locked'" ), '有 locked 标记防 always 解禁' );
a( false !== strpos( $js, 'var seq = 0;' ), '有请求序号防旧响应覆盖' );
a( false !== strpos( $js, 'if (my !== seq) { return; }' ), 'done/always 都做了过期判定' );
a( false === strpos( $js, "').show()" ), '不再用 show() 切可见性' );

echo "合计 $n 通过, $fail 失败\n";
exit( $fail ? 1 : 0 );
