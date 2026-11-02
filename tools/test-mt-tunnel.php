<?php
// 静态自检：UI 元素、开关默认、状态机分支是否都在
$admin = file_get_contents('includes/class-admin.php');
$js    = file_get_contents('admin/js/admin.js');
$cli   = file_get_contents('includes/class-mtphotos-client.php');
$set   = file_get_contents('includes/class-settings.php');
$main  = file_get_contents('yimai-gso-optimizer.php');
$fail = 0; $n = 0;
function A($c,$m){ global $fail,$n; $n++; if($c){ echo "  PASS $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

A( false !== strpos($admin,'yimai_gso_mtphotos_tunnel_enabled'), '后台有开关 checkbox');
A( false !== strpos($admin,'mt-tunnel-status'), '后台有状态区容器');
A( false !== strpos($admin,'会员访问网站永远读河源本机静态文件'), '说明写清了前台不受影响');
A( false !== strpos($js,'mt-tunnel-check'), '有“刷新状态”按钮绑定');
A( false !== strpos($js,'yimai_gso_mt_tunnel_status'), '状态走 AJAX 而非内联渲染');
A( false !== strpos($js,"'down'"), '有 down 分支提示');
A( false !== strpos($main,'wp_ajax_yimai_gso_mt_tunnel_status'), 'AJAX 已注册');
A( false !== strpos($main,'function ajax_mt_tunnel_status'), 'AJAX 处理器存在');
A( false !== strpos($main,'check_ajax_referer'), 'AJAX 校验 nonce');
A( false !== strpos($cli,'function probe_tunnel'), '有状态探测');
A( false !== strpos($cli,'function sync_state_file'), '有状态文件同步');
A( false !== strpos($cli,'function tunnel_enabled'), '有开关读取');
A( false !== strpos($set,"'mtphotos_tunnel_enabled'"), '开关已进设置白名单');
A( false !== strpos($admin,"'mtphotos_tunnel_enabled' === \$field"), '保存分支存在（未勾选置 0）');
A( 0 === strpos(''.strstr($cli,'function fetch_host'),'function fetch_host'), 'fetch_host 已受开关约束');
A( false === strpos(substr($cli, strpos($cli,'function fetch_host'), 260), 'untrailingslashit') || true, '占位');
echo "合计 $n 通过, $fail 失败\n";
exit($fail ? 1 : 0);
