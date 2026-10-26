# UI 组件规范（一麦GSO双擎优化 2.10.0）

> 这份文档是**唯一口径**。微信分享卡片页（share-ui-dev）和公众号同步页（sync-ui-dev）都照这里写，
> 不要在页面里再写内联 `<style>`，也不要自己给 `admin.css` 加类。
>
> 一句话原则：**先在这里找组件，找不到就回来找我（design-lead）加组件，而不是在自己页面里造一个。**

---

## 0. 加载与层叠（写页面前必须知道的三件事）

| 文件 | 职责 | 谁维护 |
| --- | --- | --- |
| `admin/css/admin.css` | 设计变量（`:root`）+ 历史规则 | 只读，禁改 |
| `admin/css/panels.css` | 主面板历史样式 | 只读，禁改 |
| `admin/css/components.css` | **统一组件层**（本规范描述的全部类） | design-lead |
| `admin/css/responsive.css` | 老页面的移动端补丁（骨架） | responsive-dev |

- 加载顺序固定为：`admin.css → panels.css → components.css → responsive.css`，
  四个 `wp_enqueue_style` 都在 `YIMAI_GSO_Admin::enqueue_assets()` 里，依赖都是 `array('yimai-gso-admin')`。
- 因后加载，`components.css` 对同名老类是「后者胜出」——所以它**只**覆盖了两个历史类
  （`.yimai-gso-badge`、`.yimai-gso-empty`），且都写成向后兼容的超集。其它老页面视觉不受影响。
- 所有类名统一 `yimai-gso-` 前缀 + BEM 式后缀（`-head` / `-body` / `-k` / `-v` / `-hint`），
  状态一律用 `is-*`。

### 断点（只有这四档，其它值一律不认）

| 断点 | 含义 | 组件在这一档做什么 |
| --- | --- | --- |
| `≤1200px` | 桌面窄屏 | 指标卡栅格收窄、字段 label 列变窄 |
| `≤960px` | 中屏 | 页头上下排、区块留白收紧、标题降级 |
| `≤782px` | **WordPress 后台移动端分界**（WP 在这一档折叠左侧菜单） | 字段单列、按钮全宽竖排、表格横滑、字号抬到 14px、点击区 44px、页面留白 12px 14px |
| `≤480px` | 小屏手机 | 指标卡单列、键值列表竖排、留白再压 |

> `782` 不是随手取的：WordPress 自己在 `max-width: 782px` 折叠管理菜单，内容区宽度会突变。
> 组件必须同档变化，否则会出现「菜单折了但卡片还四列」的畸形状态。**写死 782，不要写 768。**

---

## 1. 设计令牌（只读变量，不要复制值）

全部来自 `admin.css :root`，`components.css` 只补了缺项：

| 类别 | 变量 | 说明 |
| --- | --- | --- |
| 主色 | `--yimai-gso-primary` `#6366f1`、`-primary-light`、`-primary-dark` | 按钮、链接、焦点环 |
| 功能色 | `--yimai-gso-success` `-warning` `-danger` `-info` | 状态色 |
| 中性色 | `--yimai-gso-text` `-text-muted` `-gray` `-gray-light` `-border` `-bg` `-card` | 文字与背景 |
| 圆角 | `--yimai-gso-radius` 16px、`-radius-sm` 8px、`-radius-xs` 6px、`-radius-lg` 20px、`-radius-pill` 999px | 卡片用 radius，控件用 sm |
| 阴影 | `--yimai-gso-shadow-sm` `-shadow` `-shadow-md` `-shadow-lg` | 卡片默认 `-shadow`，指标卡 `-shadow-sm` |
| 间距 | `--yimai-gso-gap-xs` 4 · `-sm` 8 · `-md` 12 · `-lg` 16 · `-xl` 20 · `-2xl` 24 | 只能取这七档 |
| 字号 | `--yimai-gso-font-xs` 12 · `-sm` 13 · `-base` 14 · `-md` 15 · `-lg` 18 · `-xl` 20 · `-title` 22 | 正文下限 14px |
| 触控 | `--yimai-gso-tap` 44px、`--yimai-gso-control-h` 38px | 移动端最小可点高度 |
| 状态三件套 | `--yimai-gso-{success,warning,danger,info,muted,primary}-{soft,ink,line}` | soft=底、ink=字、line=边 |

写页面时：**能用变量就用变量**。写 `#6366f1` 这种硬编码等同给自己埋回归。

---

## 2. 组件清单

> 每个组件都给了可直接复制的骨架。骨架里的文字是示例，结构和类名不要改。

### 2.1 页面根 `.yimai-gso-page`

**用途**：每个插件页面的内容根节点，负责字号 / 行高 / 颜色，并保证 `min-width:0`（这条是 375px 不横向溢出的前提）。

```html
<div class="wrap yimai-gso-wrap">
    <div class="yimai-gso-page">
        <!-- 页头 + 区块 -->
    </div>
</div>
```

- **移动端**：不含任何断点规则，只做底座；留白由 `admin.css` 的 `.yimai-gso-wrap` 决定，`≤782px` 时被收紧成 `12px 14px`。
- **不要用它的时候**：页面里已经有 `.wrap.yimai-gso-wrap` 且只放一行提示（这种直接用 `.yimai-gso-notice`），不要再套一层。

### 2.2 页头 `.yimai-gso-page-head` / `.yimai-gso-page-desc`

**用途**：页面标题 + 右侧操作区 + 一行说明。标题用 `<h1>` 或历史类 `.yimai-gso-page-title` 都可以。

```html
<div class="yimai-gso-page-head">
    <div>
        <h1><span class="dashicons dashicons-share"></span>微信分享卡片</h1>
        <p class="yimai-gso-page-desc">
            微信内转发时只有一个裸链接，是因为页面没有接入微信 JS-SDK。
        </p>
    </div>
    <div class="yimai-gso-actions">
        <a class="yimai-gso-btn-modern-secondary" href="#">测试分享</a>
        <a class="yimai-gso-btn-modern-primary" href="#">保存设置</a>
    </div>
</div>
```

- **移动端**（≤782）：`flex-direction: column`，标题 18px，操作区 `width:100%` 并随 `.yimai-gso-actions` 竖排全宽。
- **不要用它的时候**：区块内部的小标题（用 `.yimai-gso-section-head`）；一个页面里有多个并列标题块的时候（那是两个 section，不是一个 page-head）。

### 2.3 指标卡 `.yimai-gso-stat-grid` + `.yimai-gso-stat`

**用途**：页面顶部的 3~5 个状态摘要（凭据是否配置、缓存是否有效、站点域名）。上行 K 说明、下行 V 值。**这就是分享页原 `.ymwx-card` 的正式替代品。**

```html
<div class="yimai-gso-stat-grid">
    <div class="yimai-gso-stat">
        <p class="yimai-gso-stat-k">公众号凭据</p>
        <p class="yimai-gso-stat-v is-pass">已配置</p>
    </div>
    <div class="yimai-gso-stat">
        <p class="yimai-gso-stat-k">jsapi_ticket 缓存</p>
        <p class="yimai-gso-stat-v is-muted">无</p>
    </div>
</div>
```

- 值的状态着色：`.is-pass` / `.is-warn` / `.is-fail` / `.is-info` / `.is-muted`（对应旧 `.ymwx-card-v.ok` / `.bad`）。
- **移动端**：`≤782px` 两列（`repeat(2, minmax(0,1fr))`），`≤480px` 单列；K 抬到 14px、V 抬到 18px；卡片内边距收到 12px。
- **不要用它的时候**：需要展示 6 条以上的同类数据（那是表格）；值是一段可编辑内容（那是 `.yimai-gso-field`）；需要用徽章表达状态的地方不要拿值着色凑（优先 `2.5` 徽章）。

### 2.4 区块 `.yimai-gso-section` / `-section-head` / `-section-body`

**用途**：一块设置、一组结果、一个子功能。head 带浅底和分隔线，可放标题和右侧徽章/按钮。

```html
<div class="yimai-gso-section">
    <div class="yimai-gso-section-head">
        <h2><span class="dashicons dashicons-admin-network"></span>公众号与开关</h2>
        <span class="yimai-gso-badge is-info">必填 2 项</span>
    </div>
    <div class="yimai-gso-section-body">
        <!-- 字段 / 表格 / 提示条 -->
    </div>
</div>
```

- **移动端**（≤782）：head 竖排、标题 14px、body 内边距 12px；head 里的徽章不会拉满整条（`align-self:flex-start`），按钮组会满宽。
- **不要用它的时候**：只放一句话（用 `.yimai-gso-notice`）；需要 section 套 section 超过一层（说明信息架构有问题，改用 tabs 或分页）；需要 JS 折叠的「手风琴」——本项目不做需要 JS 才能展开的组件。

### 2.5 两栏字段 `.yimai-gso-field` / `-field-label` / `-field-control` / `-field-hint`

**用途**：替代 WordPress 的 `<table class="form-table">`。左 label、右 control；控件样式统一接管。

```html
<div class="yimai-gso-field">
    <label class="yimai-gso-field-label" for="wechat_app_id">AppID<span class="required">*</span></label>
    <div class="yimai-gso-field-control">
        <input type="text" id="wechat_app_id" name="wechat_app_id" value="wx1234567890abcdef" autocomplete="off">
        <p class="yimai-gso-field-hint">公众号后台「设置与开发 → 基本配置 → 开发者ID」。</p>
    </div>
</div>

<div class="yimai-gso-field">
    <span class="yimai-gso-field-label">启用</span>
    <div class="yimai-gso-field-control">
        <label><input type="checkbox" name="enabled" value="1" checked> 在微信内接管分享标题、描述与缩略图</label>
    </div>
</div>
```

- 控件支持：`input[type=text|url|password|number|email|search|tel]`、`select`、`textarea`，全部 `width:100%` + 38px 高（移动端 44px）；**不支持**在 field 里放自定义样式的按钮组（用 `.yimai-gso-actions` 单独一行）。
- 提示文案：`.yimai-gso-field-hint`，可加 `.is-error` / `.is-success` 着色。
- **移动端**（≤782）：塌成单列（`grid-template-columns: 1fr`），label 在上、control 在下、control 占满宽度、控件高度 44px。
- **不要用它的时候**：两个字段要并排（那会破坏单列规则，改成一个字段 + 一段 hint）；只是只读信息展示（用 `.yimai-gso-kv`）；WP 原生 `form-table` 已经在用的老页面 —— 迁移是逐步的，别为了统一风格去动老页面的表单结构（回归面太大）。

### 2.6 状态徽章 `.yimai-gso-badge`

**用途**：一个短标签表达一个状态。**只放 1~4 个字或一个短词**。

```html
<span class="yimai-gso-badge is-pass">已通过</span>
<span class="yimai-gso-badge is-warn">部分缺失</span>
<span class="yimai-gso-badge is-fail">失败</span>
<span class="yimai-gso-badge is-info">信息</span>
<span class="yimai-gso-badge is-muted">未启用</span>
```

- 兼容：老页面在用的 `.yimai-gso-badge.green / .gray / .blue` 与无修饰的计数器徽章**保持原样**，不要在新页面继续用。
- **移动端**：字号 12→14px、内边距微调；徽章永远 `white-space:nowrap`，不会在中间折断。
- **不要用它的时候**：文案超过 6 个字（用 `.yimai-gso-notice` 或表格里的普通文本）；需要可点击（徽章不是按钮，可点区域会小于 44px，请用 `.yimai-gso-tab` 或 `.button`）；表达「进度百分比」（用指标卡 V 或表格列）。

### 2.7 表格 `.yimai-gso-table-wrap` + `.yimai-gso-table`

**用途**：多列数据（同步记录、文章列表、诊断结果）。**外层负责横滑，内层保持宽度。**

```html
<div class="yimai-gso-table-wrap">
    <table class="yimai-gso-table">
        <thead>
            <tr>
                <th>文章标题</th>
                <th class="is-nowrap">状态</th>
                <th class="is-nowrap">同步时间</th>
                <th class="is-nowrap is-num">字数</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>晨间 15 分钟流瑜伽：从呼吸开始的自我修复</td>
                <td class="is-nowrap"><span class="yimai-gso-badge is-pass">已同步</span></td>
                <td class="is-nowrap">2026-10-27 09:12</td>
                <td class="is-nowrap is-num">1,842</td>
            </tr>
        </tbody>
    </table>
</div>
```

- 单元格修饰：`.is-nowrap`（状态/时间/media_id 等短字段）、`.is-num`（数字右对齐等宽数字）。
- **移动端**（≤782）：`.yimai-gso-table-wrap` 保持 `overflow-x:auto` + `-webkit-overflow-scrolling:touch`；`.yimai-gso-table` 给 `min-width:720px`，**列宽不压缩、文字不缩字号**，靠手指左右拖。
  - 为什么是 720：560px 时 6 列结构（4 列 `is-nowrap`）里唯一可伸缩的「错误原因」列只剩 83~115px，长 errmsg 会逐字换行成竖线、行高飙到 260px 以上；720px 时同列 146~210px（≈10~13 字/行），行高回落到 130px 级。这个数值与 `responsive.css` 侧的量法一致，避免「老表 720、新表 560」两套口径。
  - **窄表的代价（已知、可接受）**：2 列的窄表（如分享页「常见错误码」）自然宽度只有约 320px，被 720 的全局下限撑开后，手机上会多滑约 160px。它原本在 560 下就已经超出 375 视口要横滑，所以这不是新增的滚动类型，只是多滑一点。
  - **它不是万能的**：`min-width` 只保证表格整体 ≥720，列宽分配仍由内容决定。若某一列塞进不可断的长英文 token（如超长 media_id 或 URL 当标题），该列 min-content 会把表格撑到 1000px 以上，可伸缩列依旧可能被挤到 90px 以下。真的遇到这种数据，正确做法是让那一列可断词（`overflow-wrap:anywhere`），而不是继续加 `min-width`。
- **不要用它的时候**：只有 1~2 列（用 `.yimai-gso-kv` 或指标卡）；表格里要放表单控件（改用 `field` 列表）；想给表格单独写 `font-size:11px` 硬塞进手机 —— 这是明确禁止的做法。
- **当前使用方（改 `min-width` 前先看这张表）**：
  | 页面 | 结构 | 720 下的表现 |
  | --- | --- | --- |
  | 公众号同步页「最近同步任务」 | 6 列，4 列 `is-nowrap`，可伸缩列＝文章 / 错误原因 | 错误原因列 210px、行高 133px（修复前 115px / 264px） |
  | 微信分享页「常见错误码」 | 2 列窄表（errcode + 含义与处理），自然宽度 ≈320px | 被迫撑到 720px，比自然宽度多滑 400px（560 时代同样要滑，只多 160px） |

### 2.8 选项卡 `.yimai-gso-tabs` + `.yimai-gso-tab`

**用途**：同页面内几个并列子视图的切换。**纯链接实现，靠页面跳转，不写 JS。**

```html
<ul class="yimai-gso-tabs">
    <li><a class="yimai-gso-tab is-active" href="?page=yimai-gso-wechat&tab=credential">凭据与开关</a></li>
    <li><a class="yimai-gso-tab" href="?page=yimai-gso-wechat&tab=share">分享字段</a></li>
</ul>
```

也支持 `<button class="yimai-gso-tab">`（配合已有 JS 的页面），但不要指望它自动切换。

- **移动端**（≤782）：tab 栏整体 `overflow-x:auto` 横滑，单个 tab 最小高度 44px、字号 14px。
- **不要用它的时候**：tab 数量 ≥ 7（用户点不到，改用左侧分组或分区滚动）；tab 之间是「上一步/下一步」关系（那是 `.yimai-gso-actions` 里的按钮）；需要 JS 做 tab 内容切换的动画（本项目不做）。

### 2.9 提示条 `.yimai-gso-notice`

**用途**：页面内的行内提示 —— 保存成功、接口报错、配额提醒、操作说明。

```html
<div class="yimai-gso-notice is-success"><p><strong>已保存。</strong>缓存已重置。</p></div>

<div class="yimai-gso-notice is-error">
    <p><strong>接口错误 45009：</strong>reach max api daily quota limit</p>
    <p>errcode 45009 · 2026-10-27 09:12</p>
</div>
```

- 变体：`.is-success` / `.is-error` / `.is-warning` / `.is-info`；不加修饰是中性灰。
- **移动端**（≤782）：内边距收到 12px、字号 14px、长 errmsg 自动断词，不横向溢出。
- **不要用它的时候**：全局一次性通知（那是 WP 的 `.notice`，插件里已有先例）；页面顶部常驻摘要（用指标卡）；需要用户点击关闭的提示（本项目不引入 JS 交互）。

### 2.10 操作区 `.yimai-gso-actions`

**用途**：一屏之内的按钮组，DOM 顺序 = 视觉顺序。主按钮在前（左），次按钮在后。

```html
<div class="yimai-gso-actions">
    <button type="button" class="yimai-gso-btn-modern-primary">同步到草稿箱</button>
    <a class="yimai-gso-btn-modern-secondary" href="#">仅预览</a>
    <button type="button" class="button">WordPress 原生按钮</button>
    <span class="is-spacer"></span>
    <button type="button" class="yimai-gso-btn-modern-danger">清空记录</button>
</div>
```

- `.is-spacer` 是「把后面的按钮推到右边」的占位元素，只在一条横排里有效。
- **移动端**（≤782）：`flex-direction: column` + `align-items: stretch`，每个直接子按钮 `width:100%`、最小高度 44px。
- **44px 不是自动就有的，它有前提条件（t14 修过一次）**：`admin.css` 用
  `.wp-core-ui .yimai-gso-wrap .yimai-gso-btn-modern-*`（特异性 **(0,3,0)**）声明 `min-height:auto !important`。
  两边都带 `!important` 时 CSS 先比特异性，所以组件层这条必须也是 **(0,3,0)**：
  `.yimai-gso-page .yimai-gso-actions > .yimai-gso-btn-modern-* { … !important }`。
  推论三条，写页面时必须守住：
  1. 这些按钮**必须**放在 `.yimai-gso-page` 里面，否则前缀不匹配、直接掉回 41px；
  2. 你**不要**在页面里再写 `min-height`/`height` 覆盖它 —— 任何低于 (0,3,0) 的写法都会输给 admin.css；
  3. 需要更高权重时**优先补类名（前缀）而不是再堆一个 `!important`**，否则下一个页面又得继续堆。
  完整契约见本文 **§6.1 权重契约**。
- **不要用它的时候**：单个按钮（直接放按钮，不用包一层）；表格行内的小操作（用链接或徽章样式的行内按钮，`.yimai-gso-actions` 会让它满宽）；页脚固定的保存栏（本项目禁止 `position:fixed` 的大面积浮层）。

### 2.11 空状态 `.yimai-gso-empty`

**用途**：列表/表格没有数据时的占位，告诉用户下一步做什么。

```html
<div class="yimai-gso-empty">
    <div class="yimai-gso-empty-icon">📄</div>
    <div class="yimai-gso-empty-title">还没有同步记录</div>
    <div class="yimai-gso-empty-hint">选中一篇已发布文章，点「同步到草稿箱」后这里会出现结果。</div>
</div>
```

- 兼容：老页面里的 `.yimai-gso-empty`（无子元素、只放 `<p>`）保持原样，视觉不变。
- **移动端**（≤782）：内边距 60px→24px，字号 14px；放在表格单元格里时自动去掉边框（`td .yimai-gso-empty`）。
- **不要用它的时候**：加载中（用 `.yimai-gso-loading` 或文字提示）；出错（用 `.yimai-gso-notice.is-error`）；一句话的空提示且不解释原因（那就用 `.yimai-gso-field-hint`）。

### 2.12 键值列表 `.yimai-gso-kv`

**用途**：只读的成对信息 —— AppID、域名、缓存过期时间、最近错误码。

```html
<div class="yimai-gso-kv">
    <div class="yimai-gso-kv-k">AppID</div>
    <div class="yimai-gso-kv-v">wx1234567890abcdef</div>
    <div class="yimai-gso-kv-k">JS 安全域名</div>
    <div class="yimai-gso-kv-v">www.yimaiyoga.com</div>
</div>
```

也支持 `<dl>`：`<dt class="yimai-gso-kv-k">` / `<dd class="yimai-gso-kv-v">`（或直接用 `dt` / `dd`，样式已覆盖）。

- **移动端**：`≤782px` 左列压到 80~112px，值列 `overflow-wrap:anywhere`（长 URL 不会撑破）；`≤480px` 变成上下两行（标签在上、值在下）。
- **不要用它的时候**：值需要编辑（用 `field`）；同一组键值超过 6 对（用表格）；值是长段落/JSON（用 `<pre>` 包在 `.yimai-gso-section-body` 里）。

### 2.13 手机卡片预览 `.yimai-gso-preview-card`

**用途**：微信分享卡片页右侧那个「转发出来长什么样」的手机模拟卡。**固定 320px 宽**，一比一贴近微信真实气泡比例。

```html
<div class="yimai-gso-preview-card">
    <div class="yimai-gso-preview-card-media">
        <img src="<?php echo esc_url( $cover_url ); ?>" alt="分享封面预览">
    </div>
    <p class="yimai-gso-preview-card-title"><?php echo esc_html( $title ); ?></p>
    <p class="yimai-gso-preview-card-desc"><?php echo esc_html( $desc ); ?></p>
    <p class="yimai-gso-preview-card-meta">
        <span class="dashicons dashicons-admin-site-alt3"></span> yimaiyoga.com
    </p>
</div>
```

- 子元素语义：`-media` 封面区（170px 高、`object-fit:cover`）、`-title`（最多两行截断）、`-desc`（最多两行截断）、`-meta`（域名/来源）。
- **移动端**（≤782）：`max-width:100%` 铺满、封面降到 156px、正文抬到 14px。**不要**在页面里写死 `width:320px`，那会把 375px 撑出横向滚动条（原 `.ymwx-preview` 的 bug 就在这）。
- **不要用它的时候**：真实卡片预览以外的任何地方（它不是普通卡片容器）；需要显示多张卡片的对比（改为列表 + 徽章）。

---

## 3. 整页骨架（直接抄）

```php
<div class="wrap yimai-gso-wrap">
    <div class="yimai-gso-page">

        <div class="yimai-gso-page-head">
            <div>
                <h1><span class="dashicons dashicons-share"></span>公众号同步</h1>
                <p class="yimai-gso-page-desc">把已发布文章推送为公众号草稿（不自动群发）。</p>
            </div>
            <div class="yimai-gso-actions">
                <a class="yimai-gso-btn-modern-secondary" href="#">刷新状态</a>
                <button type="submit" class="yimai-gso-btn-modern-primary">同步到草稿箱</button>
            </div>
        </div>

        <ul class="yimai-gso-tabs">
            <li><a class="yimai-gso-tab is-active" href="?tab=list">文章列表</a></li>
            <li><a class="yimai-gso-tab" href="?tab=log">同步记录</a></li>
        </ul>

        <div class="yimai-gso-notice is-info"><p>草稿只在公众号后台可见，需要你手动群发。</p></div>

        <div class="yimai-gso-stat-grid">
            <div class="yimai-gso-stat">
                <p class="yimai-gso-stat-k">今日剩余配额</p>
                <p class="yimai-gso-stat-v">8 / 10</p>
            </div>
            <div class="yimai-gso-stat">
                <p class="yimai-gso-stat-k">草稿箱文章</p>
                <p class="yimai-gso-stat-v">37</p>
            </div>
        </div>

        <div class="yimai-gso-section">
            <div class="yimai-gso-section-head"><h2>可同步文章</h2></div>
            <div class="yimai-gso-section-body">
                <div class="yimai-gso-table-wrap">
                    <table class="yimai-gso-table"><!-- thead / tbody --></table>
                </div>
            </div>
        </div>

    </div>
</div>
```

---

## 4. 迁移对照表（重构老页面时照这张表换）

| 旧写法 | 新写法 | 备注 |
| --- | --- | --- |
| `.ymwx-wrap` | `.wrap.yimai-gso-wrap > .yimai-gso-page` | 复用插件统一的页壳 |
| `.ymwx-cards` / `.ymwx-card` | `.yimai-gso-stat-grid` / `.yimai-gso-stat` | `-k` / `-v` 对应 `-card-k` / `-card-v` |
| `.ymwx-card-v.ok` / `.bad` | `.yimai-gso-stat-v.is-pass` / `.is-fail` | 也可以直接换成 `is-pass` / `is-fail` 徽章 |
| `.ymwx-preview`（固定 320px） | `.yimai-gso-preview-card` | 移动端自动铺满，不再溢出 |
| `.ymwx-preview .t/.d/.h` | `.yimai-gso-preview-card-title/-desc/-meta` | 两行截断已内置 |
| `.ymwx-checks` / `.ymwx-check` | `.yimai-gso-kv` | 或 `.yimai-gso-table`（字段多时） |
| `.ymwx-result` | `.yimai-gso-notice.is-success/.is-error` | |
| `<table class="form-table">` | `.yimai-gso-field` 列表 | 只在**新写**的区块里换；老区块不动 |
| 页面内联 `<style>` | 删掉，改用组件类 | 这是本次统一的核心理由 |
| `style="width:320px"` 之类的硬宽 | 交给组件断点 | 硬宽是 375px 溢出的头号原因 |

---

## 5. 375px 验收清单（改完页面自己先过一遍）

- [ ] `document.documentElement.scrollWidth <= 375`（无横向滚动条）
- [ ] 表格都包在 `.yimai-gso-table-wrap` 里，列文字**没有被压到 11px**
- [ ] `.yimai-gso-field` 是单列：label 在上、control 在下、control 满宽
- [ ] `.yimai-gso-actions` 竖排，每个按钮 `width:100%` 且高度 ≥44px
       （**测的时候 body 必须同时带 `wp-admin wp-core-ui` 两个类**，少了 `wp-core-ui`，
       admin.css 里 `(0,3,0)` 的 `min-height:auto !important` 不匹配，会量出假 PASS —— t14 就是这么漏的）
- [ ] 所有正文字号 ≥14px（徽章在移动端也是 14px）
- [ ] 页面留白 `12px 14px`（不是 `20px 40px`）
- [ ] 没有任何 `position:fixed` 的大面积浮层
- [ ] 没有新增外部字体 / CDN

**自检工具**：浏览器打开 `docs/ui-demo.html`（静态页，不进插件包），把视口调到 375px。
页面顶部会自动打印 PASS/FAIL，并且内嵌了一个真实 375px 视口的 iframe（iframe 会真正触发媒体查询）。

---

## 6. 权重契约与踩坑清单

### 6.1 权重契约（覆盖 admin.css 的 (0,3,0) 规则）

**规则**：`admin.css` / `panels.css` 里凡是写成 `.wp-core-ui .yimai-gso-wrap .xxx` 的规则，特异性都是 **(0,3,0)**。
组件层若要覆盖同一个属性，选择器**必须 ≥ (0,3,0)**；
两边都带 `!important` 时 CSS 先比特异性、再比源码顺序 —— 特异性输了，`!important` 再多也没用。
提高权重的手段是**补有意义的类名前缀**（`.yimai-gso-page`），不是继续堆 `!important`。

**已经形成的契约（改动前 → 改动后）**：

| 目标 | admin.css 压制规则（特异性） | components.css 规则 | 原 → 新 |
| --- | --- | --- | --- |
| 移动端 `.yimai-gso-btn-modern-primary/secondary/danger`、`.yimai-gso-btn-activate`、`.yimai-gso-btn-danger` 在 `.yimai-gso-actions` 里 | `.wp-core-ui .yimai-gso-wrap .yimai-gso-btn-modern-*`（0,3,0，`min-height:auto !important`） | `.yimai-gso-page .yimai-gso-actions > .yimai-gso-btn-modern-*`（`width/min-height/margin !important`） | **(0,2,0) → (0,3,0)** |
| 页面壳 `.yimai-gso-wrap` 的内边距 | `body.wp-admin .yimai-gso-wrap`（0,2,1）/ `body.wp-admin div.wrap.yimai-gso-wrap`（0,3,1） | 同选择器逐字复制 + `padding:12px 14px !important` | 0,2,1+0,3,1 → 不变（同权重靠后加载胜出，已够） |
| `.yimai-gso-field-control` 内 `input/select/textarea` 的宽度与高度 | 无。`admin.css` / `panels.css` 里没有任何 `.yimai-gso-wrap` 前缀的 input 规则（已 grep 全量核对，只有 `.apv3-retention-row input`、`.yimai-gso-rewrite-search-row input[type=search]` 两个页面私有选择器） | `.yimai-gso-field-control input[...]`（0,2,1） | 不变（无竞争，已足够） |
| `.yimai-gso-table` 系列 | 无。`admin.css` 的表格规则都挂在 `.form-table` / `.wp-list-table` / `.yimai-gso-history-table-wrap` / `.yimai-gso-404-data-table` 等私有类上，不匹配本组件 | `.yimai-gso-table / th / td`（0,1,0 ~ 0,1,1） | 不变（无竞争） |
| `.yimai-gso-actions > *` 的 `box-sizing` | 无。`admin.css` 对 `.yimai-gso-btn-modern-*` 只声明 padding，没声明 `box-sizing` | `.yimai-gso-actions > *`（0,1,0） | 不变（无竞争；但它是 t1 修横向溢出的前提，别删） |

**审计方法（照做，别抽样）**：

```bash
grep -n '!important' admin/css/components.css        # 逐条看，只有 4 条声明 + 4 条注释
grep -n 'wp-core-ui .yimai-gso-wrap' admin/css/admin.css   # 52 行，目标类只有 btn-* / tab-modern / btn-sm/lg/icon / notice
grep -n 'yimai-gso-wrap' admin/css/panels.css        # 空
```

**禁止事项**：
- 给已经 (0,3,0) 的规则再加 `!important` 去赢别的 `!important`（权重战争没有终点）；
- 在页面里写 `style="height:..."` 或自己的 `.yimai-gso-page .yimai-gso-actions > …` 补丁（那是把缺陷复制到每个页面）；
- 把 `.yimai-gso-actions` 放到 `.yimai-gso-page` 外面（前缀不匹配 → 44px 失效）。

> 现状记录：`admin/css/wechat.css` 第 285-288 行有一段 share-ui-dev 写的等价补丁
> （`.yimai-gso-page .yimai-gso-actions > .yimai-gso-btn-modern-primary/secondary`，同样是 (0,3,0)、同样三个值）。
> 源头修好后它**冗余但无害，两处值完全相同**（实测加不加 wechat.css，按钮高度都是 44/45px），
> 保留即可，不需要清理。

### 6.2 其它坑

1. **`width:100%` + `padding` 必须有 `box-sizing:border-box`。**
   `admin.css` 的 `.yimai-gso-btn-modern-*` 是 content-box，375px 下 `100%` 会变成 `351+48=399px`，直接横向溢出。
   组件层已在 `.yimai-gso-actions > *` 上统一加了 `border-box`，你新写按钮组时不要绕开它。
2. **`min-height` 会被老规则顶回去 —— 这是特异性问题，不是 `!important` 数量问题。**
   `.yimai-gso-btn-modern-*` 用 `.wp-core-ui .yimai-gso-wrap .xxx`（0,3,0）+ `min-height:auto !important`，
   组件层因此把选择器提到 `(0,3,0)`（`.yimai-gso-page` 前缀）并用同数量的 `!important` 对抗。
   症状很好认：**手机上按钮只有 41px**（= padding 10 + 行高 21 + padding 10，`min-height` 被压成 `auto`）。
   详见 §6.1。
3. **grid/flex 子项溢出，先怀疑少了 `min-width:0`。**
   长 URL、长英文词会把 1fr 列顶宽，`overflow-wrap:anywhere` 只解决「折词」，不解决「子项不肯缩」。
4. **不要给 `.yimai-gso-section` 加 `overflow:hidden`。**
   会裁掉里面的下拉和浮层；圆角已经由 `section-head` 单独处理。
5. **不要在移动端把字号压到 12px。**
   用户的验收口径是「字不小于 14px」，压字号过验收是最容易被退回的改法。
6. **`782` 只能写 `max-width: 782px`。**
   写 `768` 会在 769~782 区间出现「菜单已折叠但组件还是桌面布局」的空档。
7. **不要自己加 `@media` 到页面内联样式里。**
   需要新断点行为就回来加组件，`responsive.css` 是给老页面补丁用的，不是新页面的地方。
8. **自检页必须模拟真实 WP 后台 body。**
   `<body class="wp-admin">` 不够，要 `wp-admin wp-core-ui` —— 否则 `.wp-core-ui …` 的规则不匹配，
   自检会在 44px 上给出假 PASS（t1 就是这么漏掉 §6.1 那个权重问题的，t14 补回来）。

---

## 7. 责任边界

| 文件/范围 | 归属 |
| --- | --- |
| `admin/css/components.css`、本规范 | design-lead |
| `admin/css/responsive.css`（老页面补丁） | responsive-dev |
| 微信分享卡片页迁到组件类 | share-ui-dev（结构照第 3 节，类名照第 2 节） |
| 公众号同步页 UI | sync-ui-dev |
| `admin/css/admin.css`、`panels.css` | **谁都不改**（9681 / 2352 行，改动连锁回归） |

需要新组件时：先在本文件里加「用途 / HTML / 移动端 / 不要用」四段，再在 `components.css` 里实现，最后同步更新 `docs/ui-demo.html`。顺序反了就会出现「文档里有、样式里没有」的坑。
