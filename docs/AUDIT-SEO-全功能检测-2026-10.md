# 一麦GSO 插件 v2.8.0 · SEO 全功能检测审计报告

- 审计日期：2026-10-26
- 审计人：seo-auditor（AgentTeams 任务 t1）
- 审计对象：yimai-gso-optimizer v2.8.0（44k 行 PHP）
- 线上站：https://www.yimaiyoga.com （WP + nginx + 宝塔 + Cloudflare）
- 方法：代码审查（钩子闭环/AJAX 注册/设置键读写闭环/恒真假条件）+ 线上 curl 取证 + 设置键交叉验证
- 结论分级：【确证bug】（代码与线上双重证据）/【疑似】（代码可疑，需运行时确认）/【建议】（可优化项）

> 404 接管功能已知失效，由任务 f1 专门修复。本报告涉及 404 的发现均标注「供 f1 参考」，不重复展开。

---

## 1. Sitemap 生成（includes/class-sitemap-generator.php）

**功能说明**：接管 `/sitemap.xml`（索引）、`/sitemap-post.xml`、`/sitemap-taxonomy-{tax}.xml`、`/sitemap.html`，禁用 WP 内置 sitemap（`wp_sitemaps_enabled → false`，:46），文章发布/删除后异步重生成静态文件到 `uploads/yimai-gso-sitemap/`（:484-533）。

**代码状态**：
- 钩子链完整：rewrite 规则（:52-58）→ query_vars（:60-69）→ `template_redirect` 优先级 1 分发（:74-113）→ `serve_sitemap()` 依 var 输出。
- `robots.txt` 接管同样走 rewrite（`^robots\.txt$`，:57）+ `robots_txt` filter 清空 WP 默认（:48）。
- AI sitemap 扩展命名空间 `xmlns:ai`（:161）由 `yimai_gso_sitemap_entry_filter`（:179）注入。

**线上表现**（curl 2026-10-26）：
- `/sitemap.xml` 200，sitemapindex 含 post + category + post_tag 三张子表，lastmod=2026-10-26T11:39:27+08:00（当天，新鲜）。
- `/sitemap-post.xml` 200，含 mobile + ai 命名空间、每条 URL 带 `ai:ready/ai:schema_types/ai:platforms` 扩展。
- `/sitemap-taxonomy-category.xml`、`/sitemap-taxonomy-post_tag.xml` 200，内容正确（中文标签 URL 已解码）。
- `/sitemap.html` 200（31173 字节）。

**问题清单**：
1. 【建议】`sitemap-post.xml` 中首页条目 `<loc>https://www.yimaiyoga.com/</loc>` 与文章混排于「post sitemap」，首页来自 `get_posts` 查询结果（首页是 page 且 post_type 匹配）；语义上无碍但索引文件未单列 page sitemap，若未来页面增多建议拆分。
2. 【建议】`calculate_priority()`（:404-434）内 `is_front_page()` 在 sitemap 渲染上下文（template_redirect 已进入主查询）恒为「首页条目时为真」，但该函数被逐条文章调用时此判断基本不成立；属死分支而非失效。
3. 【建议】`generate_sitemap_file()` 有 1 小时 transient 缓存（:487-491），发布文章后 `on_post_publish` 调度的单次事件可能被缓存挡掉，静态文件最长滞后 1 小时——但线上 sitemap 由 rewrite 动态渲染提供（静态文件只是备份产物），用户可见的 sitemap 实时性不受影响。

**结论：正常**。sitemap 全链路（索引/子表/HTML/发布钩子）代码与线上输出一致。

---

## 2. robots.txt 生成（class-sitemap-generator.php render_robots + Crawler_Editor）

**功能说明**：插件接管 robots.txt；若设置键 `yimai_gso_robots_content` 有自定义内容则输出之，否则输出默认规则 + `yimai_gso_robots_crawler_block` filter 注入 AI 爬虫段（由 class-crawler-editor.php 提供）。

**代码状态**：
- rewrite `^robots\.txt$`（:57）+ `serve_sitemap` 中 `yimai_gso_robots` 分支（:105-108）→ `render_robots()`（:343-402）。
- `robots_txt` filter `__return_empty_string`（:48）确保 WP 虚拟 robots 不叠加。

**线上表现**（curl）：
- 200 text/plain；含 WP 屏蔽段、Sitemap 三行（sitemap.xml / sitemap.html / llms.txt）、AI 爬虫规则段（26 个 UA，全部 Allow，含 GPTBot/ClaudeBot/PerplexityBot/DeepSeekBot/KimiBot 等，标注「由 GEO 爬虫编辑器管理」）。
- ⚠️ 注意一个重要事实：线上 robots.txt 的 AI 爬虫段格式与代码默认段（:396-401 仅 5 个 UA：GPTBot/ChatGPT-User/Claude-Web/PerplexityBot/Google-Extended）**不一致**，说明实际生效的是 `yimai_gso_robots_crawler_block` filter（爬虫编辑器输出）或自定义 `yimai_gso_robots_content`。链路工作正常。
- `Disallow: /wp-includes/` 会阻止读取 `/wp-includes/js/` 等静态资源——主流做法通常不屏蔽 wp-includes（Google 官方建议允许），【建议】层面记录。

**问题清单**：
1. 【建议】`Disallow: /wp-includes/`、`Disallow: /wp-content/plugins/` 属过度屏蔽，可能影响搜索引擎渲染页面（JS/CSS 资源位于这些目录）。Google 搜索中心明确建议不要屏蔽 wp-includes。修复建议：改为仅屏蔽动态入口或直接删除这两行。
2. 【建议】默认分支的 AI 爬虫列表（5 个 UA）与爬虫编辑器（26 个 UA）不一致属预期（编辑器优先），但代码默认段含已停用 UA 名 `Claude-Web`（Anthropic 现用 ClaudeBot），如未来编辑器被清空回退到默认段会引用过时 UA 名。

**结论：正常**（含 2 条建议）。

---

## 3. llms.txt / llms-full.txt（includes/class-llms-txt-generator.php）

**功能说明**：为 AI 大模型提供站点内容清单（llms.txt：首页/分类/近期文章/品牌简介/AI 政策/平台覆盖）与全文版（llms-full.txt）。

**代码状态**：570 行。主文件已 require（:121）并在 init 无条件实例化（:358-360）。输出内容与品牌资料（GEO 实体）联动。

**线上表现**（curl）：
- `/llms.txt` 200 text/markdown 4517 字节：品牌简介（含东部店/绿地店、课程体系）+ Optional + Core Pages + Categories（4 个分类，带描述）+ Recent Posts（6 篇，带完整摘要）+ AI Usage Policy + AI Platform Coverage（6 平台）。结构完整。
- `/llms-full.txt` 200 text/markdown 3222 字节：品牌简介 + Full Content 段，每篇文章含 H3 标题、摘要引用块、URL、Tags。

**问题清单**：
1. 【确证bug】`render_llms_full_txt()`（class-llms-txt-generator.php:187-232）输出的是「标题 + 摘要（200 字截断）+ URL + Tags」，**从不输出正文**——`get_post_excerpt()`（:369-387）仅取 `post_excerpt` 或 `wp_trim_words(content,30)` 再截 200 字。这是**设计如此**而非截断 bug，但与文件名 llms-full.txt（行业惯例 = 站点内容全文）语义不符。疑似项升级为「命名与语义不符」问题。
2. 【确证bug】**覆盖率缺口**：线上 sitemap-post.xml 含 15 条 URL（14 篇文章 + 首页），但 llms.txt「Recent Posts」与 llms-full.txt「Full Content」均只列 **6 篇**。`get_public_posts()`（:348-368）按 `max_posts` 设置取数（默认 100， sanitize 下限 10），排除分类 `excluded_categories` 亦可过滤——线上只有 6 篇说明后台 llms.txt 设置中 max_posts 被保存为 6 附近或部分分类被排除（缓存 `CACHE_KEY`（:19）与 `on_post_save`（:390-418）刷新链路正常，最近文章 1281/1262/1152 均在列，非缓存陈旧）。**8 篇文章对 AI 爬虫不可见**。修复：核对后台「llms.txt 设置」的 max_posts 与排除分类，或把默认值调高并重存。
3. 【建议】`get_public_posts()`（:348-368）`post_type` 硬编码 `'post'`，**不含 page**；首页/关于页等核心页面只进 llms.txt 的 Core Pages 段（手工配置）。

**结论：问题**（输出机制正常，但 full 版无正文 + 覆盖率缺 8 篇两处确证缺陷）。

---

## 4. SEO Meta / TDK（includes/class-seo-meta-optimizer.php，928 行）

**功能说明**：发布后自动生成 SEO 标题/描述/关键词并写入 Yoast/RankMath/通用三套 post meta（`_yoast_wpseo_title/metadesc/focuskw`、`rank_math_title/description/focus_keyword`、`title/description/keywords`），后台提供批量扫描/预览/应用（AJAX `yimai_gso_seo_meta_scan/preview/apply/stats`）与设置开关（`auto_seo_meta_enabled/skip_manual/categories`）。

**代码状态**：
- 发布钩子链完整：`transition_post_status` 优先级 15（:44）→ 开关/类型/白名单/防重入校验（:57-116）→ 入队 option（:118-158）→ 60s 后单次 cron `process_queue`（:161-215）→ `auto_generate_for_post` AI 生成（:217-291）→ `apply_preview` → `YIMAI_GSO_Publisher::write_seo_fields`（class-publisher.php:507-534）写三套 meta。
- AJAX 闭环完整：4 个 action 主文件注册（yimai-gso-optimizer.php:468-471），类内方法存在；设置保存 AJAX `yimai_gso_save_seo_meta_settings` 前后端闭环（class-admin.php:44 注册 / :1140 处理；admin/js/admin.js:1883 调用）。
- **关键架构事实：本插件不输出任何前台 `<meta name="description/keywords">` 或 `<title>`**——全代码库无 `wp_head` 输出 TDK 的函数（仅 OG/ai-* meta）。前台 description 实际由**子比主题（zibll）**或 Yoast/RankMath 读取插件写入的 meta 字段输出。插件定位是「SEO 字段写入器」而非「TDK 输出器」。

**线上表现**：
- 文章页（/archives/1281）`<meta name="description">` 内容与 AI 生成描述一致（即 `description` meta 键被主题正确读取）。✅
- 首页 description 正常。✅
- 文章页无 `<meta name="keywords">` 输出（现代 SEO 中 keywords meta 已被 Google 忽略，属可接受现状；但 `yimai_gso_keywords` 数据存在且被 og/ai-keywords/schema 使用，数据链路健康）。

**问题清单**：
1. 【确证bug】文章页 `<title>` 出现双站名拼接：「一麦瑜伽减脂塑形课怎么上？课程安排与效果答疑 | 一麦瑜伽·普拉提 – 一麦瑜伽」。「| 一麦瑜伽·普拉提」应为插件/主题自定义 title 模板，「– 一麦瑜伽」是 WP 核心 `title-tag` 的追加站名，两者叠加。原因：子比主题或本插件写入 `_yoast_wpseo_title`/`title` meta 值本身带了 `| 一麦瑜伽·普拉提` 后缀，而主题 title 输出再拼 `– 站名`。修复建议：在 write_seo_fields（class-publisher.php:529-533）写入 title 时确保不含站名后缀，或让 title 模板只由主题一层拼接。
2. 【建议】`auto_generate_for_post` 重试 `sleep(2)`（:224）在 cron 串行环境会阻塞队列，无指数退避；低优先级。
3. 【建议】发布管线 `set_zibll_meta`（class-publisher.php:559）写 `yimai_gso_keywords`，而 SEO meta optimizer 的 apply_preview 走 `write_seo_fields` 不写 `yimai_gso_keywords`（只写三套外部键），两条写入路径的字段集不一致——og/ai-meta 读 `yimai_gso_keywords` 兜底到 yoast/rankmath 键，实际能读到，链路通。

**结论：基本正常**（自动 TDK 生成/写入/前台展示链路通），1 条确证 title 拼接 bug。

---

## 5. Canonical 管理（includes/class-canonical-manager.php，约 350 行）

**功能说明**：过滤 `get_canonical_url`（手动自定义 canonical 优先 → 自动清洗 utm/fbclid/gclid 等跟踪参数），输出 rel prev/next（多页文章），编辑页 Meta Box + 批量巡检 AJAX。

**代码状态**：
- `init()`（:13-27）注册 `get_canonical_url` filter、`wp_head` rel prev/next（优先级 2）、meta box、2 个 AJAX（主文件 :498-499 也注册，**双注册但 WordPress 对同一 callback 双 add_filter 会执行两次**——见问题清单）。
- ⚠️ **双注册确认**：主文件 yimai-gso-optimizer.php:498-499 与类内 init():25-26 都 `add_action('wp_ajax_yimai_gso_canonical_batch_scan', ...)`。同一 action 注册两次 → AJAX 处理器执行两次（第二次的 wp_send_json_* 直接 die，无功能危害但浪费一次扫描计算；若处理器内有写操作会重复执行）。
- `output_rel_prev_next`（:97-122）仅 `is_singular()` 多页文章有效；分类归档分页不输出（Google 2019 已弃用 rel prev/next，影响中性）。

**线上表现**：
- 文章页 `<link rel="canonical" href="https://www.yimaiyoga.com/archives/1281">` ✅
- 带 utm_source+fbclid 访问文章页 → canonical 仍为干净 URL（跟踪参数被清洗或 WP core 本就不含参数）✅（curl 实测）
- 首页 canonical `/` ✅；分类页 canonical 正常 ✅
- rel prev/next：现网文章无多页分页，无法线上验证；代码路径存在且逻辑正确。

**问题清单**：
1. 【确证bug】AJAX 双注册（主文件 :498-499 + class-canonical-manager.php:25-26），`ajax_batch_scan`/`ajax_save_custom` 每次请求执行两遍。`ajax_batch_scan` 是纯读可幂等，但双倍 DB 扫描开销；修复：删除主文件两行或类内两行。
2. 【建议】`clean_tracking_params`（:53-95）只挂在 `get_canonical_url`（singular 上下文）；首页/归档带 utm 参数的 canonical 不清洗（WP core 对归档 canonical 会保留 query string?实测分类页 canonical 无参数——因 WP core 归档 canonical 不含查询串，风险低）。

**结论：基本正常**（canonical 输出与参数清洗线上验证通过），1 条确证双注册 bug（性能级）。

---

## 6. Schema 结构化数据（includes/class-schema-generator.php，1189 行）

**功能说明**：wp_head 输出 Article/BreadcrumbList/FAQPage/Course 等 JSON-LD；与 Yoast/RankMath 冲突检测（冲突时自动禁用自身输出）；后台重建/设置/统计 AJAX。

**代码状态**：
- `wp_head` 钩子（:20）`output_json_ld`（:60+）。
- 冲突检测：class-seo-geo-bridge.php:570 提示「检测到已安装 SEO 插件时自动禁用自身 Schema」——线上站若装 Yoast/RankMath 之一，本插件 Schema 输出会被关闭（需在线上验证实际输出来源）。

**线上表现**（文章页 /archives/1281，5 个 JSON-LD 块，全部可 JSON.parse）：
1. BreadcrumbList：3 级（首页→Body & Soul→文章）✅ —— 但 `name` 值为 HTML 实体 `Body &amp; Soul`（未解码），Schema 中出现实体转义字符。
2. Article：headline/description/datePublished/dateModified/author(Person)/publisher(Organization+logo)/mainEntityOfPage/articleSection/keywords/image 齐全 ✅ —— 但 `citation` 数组为两条**重复**的 `CreativeWork`，name 是「学员当天的状态给针对性指导」（正文小标题被误提取为引用），且同一引用出现两次（提取器重复）。
3. LocalBusiness（GEO 实体增强版，来自 class-geo-entity.php）。
4/5. WebPage × 2（citations 与 eeat 两个节点，来自 class-citation-helper / EEAT 逻辑）。

首页 5 块：HealthAndBeautyBusiness × 2（@graph 内两家门店）+ WebSite + LocalBusiness + WebPage × 2。

**问题清单**：
1. 【确证bug】Article schema `citation` 字段提取错乱：正文小标题「学员当天的状态给针对性指导」被当作引用来源，且重复输出两次。Google 富媒体规则中 citation 需指向真实外部来源（CreativeWork.url 或标识），name=内部小标题的 citation 属于噪声数据，可能触发结构化数据质量告警。定位：citation 数据生成逻辑（class-citation-helper.php 或 schema-generator 内 citation 构建）。
2. 【确证bug】BreadcrumbList `name` 含未解码 HTML 实体（`Body &amp; Soul`）。分类名来自 `get_cat_name()` 时应做 html_entity_decode；实体字符会原样进入搜索引擎解析结果。
3. 【疑似】首页 `WebSite` schema 与 `HealthAndBeautyBusiness` @graph 来自不同输出方（schema-generator vs geo-entity），共 5 块 JSON-LD 无 @graph 统一封装；Google 可解析但散乱。搜索引擎合并理解多块同域 schema 没问题，建议级别。
4. 【建议】Article.publisher.logo 使用 2026/08 上传的 PNG（cropped-一麦纯logo-绿.png）✓ 有效；`image` 字段单值（非 ImageObject 数组），Google 推荐单值可用，无需改。

**结论：问题**（核心 Article/Breadcrumb 可解析，但 citation 噪声 + 实体未解码两处确证缺陷）。

---

## 7. OG / Twitter Card / AI meta（主文件 yimai-gso-optimizer.php:674-805 + class-ai-platform-adapter.php）

**功能说明**：`output_og_meta`（:674）/`output_twitter_card_meta`（:735）/`output_ai_meta`（:771）wp_head 输出；AI 平台适配器（class-ai-platform-adapter.php:60-63）额外输出 `ai-platform:*` 系列与平台专属 Schema。

**代码状态**：
- 三个输出函数均在 init 注册（:431-433，优先级 5）；`output_organization_schema`（:806）有 GEO 实体已配置时跳过的防重复逻辑（:812-814）与首页跳过逻辑（修复注释 L2）。
- 描述回退链：`yimai_gso_excerpt` → `_yoast_wpseo_metadesc` → `rank_math_description` → `description` → `get_the_excerpt`（:691-696）。**注意 `yimai_gso_excerpt` 键无人写入**（全库检索仅 seo-geo-bridge.php:835 回填一处），实际数据来自后三个键（发布管线写入）——回退链第一环是死键，但整体链路通。

**线上表现**：
- 文章页 og:type=article、og:title/description/url/image（AI 生成配图 URL）/og:locale 全套 ✅
- twitter:card=summary_large_image + title/description/image ✅
- ai-description/ai-keywords/ai-platforms/ai-content-format/ai-schema-coverage/ai-citation-available/ai-entity-type/ai-platform:{chatgpt,gemini,deepseek,doubao,claude,perplexity} 全套 ✅
- 首页 og:type=website，og:image 使用 logo.svg（SVG 作为 og:image 兼容性差：微信/Facebook 抓图器多数不支持 SVG，无 fallback 时分享卡片无图）。

**问题清单**：
1. 【确证bug】首页 `og:image` 为 `https://www.yimaiyoga.com/wp-content/uploads/2021/05/logo.svg`（SVG）。Facebook/微信/QQ 分享抓取器不支持 SVG，分享卡片将无缩略图。代码路径：output_og_meta 缩略图回退链 `custom_logo`（主题 logo，SVG）→ `get_site_icon_url(512)`。修复：og:image 优先取 site icon（PNG）或文章首图，SVG 跳过；或给 custom_logo 检测扩展名。
2. 【建议】`ai-citation-available` 恒为 `false`（与 Article citation 的提取质量联动——引用提取器没有产出可用外部引用，见第 6 节问题 1）。
3. 【建议】twitter:card 仅文章/页面输出，首页无 twitter card（首页分享到 X 无卡片元数据）；影响小。

**结论：问题**（文章页全链路正常；首页 og:image SVG 一处确证缺陷）。

---

## 8. 智能内链（includes/class-internal-link.php，1366 行）

**功能说明**：发布后自动 AI 内链（transition_post_status 优先级 25 → 异步队列 cron）；后台全量重建（rebuild）、断链审计（broken link audit）、开关切换；锚文本策略（natural 等 4 种）、候选模式（recent/相关）、关键词库、排除分类/标签。

**代码状态**：
- 钩子链完整：`transition_post_status`(25)（:49）→ `add_to_link_queue`（:110-156）→ cron `process_link_queue`（:158）→ `insert_links`（:263-349）→ AI 规划 `ai_plan_links`（:503）→ `apply_links`（:727）。
- DISABLE_WP_CRON 精准模式下用 HTTP 触发 wp-cron.php（:133-148）——考虑周全。
- AJAX 闭环：6 个 action（rebuild×2、broken_audit×3、remove_broken）主文件注册（:505-511），toggle 开关在 class-admin.php:43 注册、admin.js:8396 调用 ✅。
- 设置键读写闭环：`internal_link_enabled/max/candidate_mode/candidate_limit/excluded_*/keyword_library/anchor_policy` 在 class-admin.php:466-504（save_internal_link_settings）保存、:552-558（渲染回显）读取、class-internal-link.php:793-838 读取使用 ✅。
- ⚠️ JS 与 PHP 预处理交叉验证：admin.js 所有 action 后端全部注册（含 404 的 13 个 action 也全部注册，无悬空调用）。

**线上表现**：文章页 /archives/1281 正文含 2 条站内文章链接（→/archives/783 锚文本「垫上普拉提」、→/archives/1152 锚文本「产后早期」），锚文本为语境化短语而非关键词堆砌，AI 内链规划真实生效 ✅。

**问题清单**：
1. 【建议】`insert_links` 内 `error_log`（:332-334，注释称「不受 DEBUG 控制」）违反主文件 T3 统一日志规范（yimai_gso_debug_log），内链规划失败时仍无条件写服务器日志；量小但与规范相悖。
2. 【建议】`ajax_toggle_internal_link`（class-admin.php:1129）直接 `update_option('yimai_gso_internal_link_enabled')`，与其他设置走 `YIMAI_GSO_Settings::set` 的路径不一致；功能等价（option 名相同），仅风格问题。

**结论：正常**。

---

## 9. SEO 推送（includes/class-seo-pusher.php，1387 行）

**功能说明**：发布后自动推送 URL 到百度/必应（IndexNow）/谷歌（Indexing API）；手动批量推送、sitemap 推送、连接测试、日志查询；百度站长验证 meta 与百度统计注入（主文件 :650-671）。

**代码状态**：
- `init()`（:36-54）注册 transition_post_status(20) → `on_post_status_change`（:70-121，含预览跳过/trash 排除/类型过滤/开关检查/防重入）→ 入队 → cron `process_seo_queue`（:752-786，每批 5 篇）→ `push_post` → `push_urls`（:158-197）按平台开关分发。
- AJAX 8 个 action 全部注册且前端全部调用（push_batch/push_sitemap/get_posts/get_logs/clear_logs/save_settings/get_stats/test_connection）✅
- 平台开关：`seo_push_{baidu,bing,google}_enabled`（:207-209 is_platform_enabled）；IndexNow key（`seo_push_bing_indexnow_key`）存在时 Bing 走 IndexNow（:312-314）。
- 设置保存：ajax_save_settings（:1365-1371 附近）写平台开关与密钥 ✅

**线上表现**：推送行为属后台动作，无法从公开端点直接验证；间接证据：`baidu-site-verification` meta 未在线上首页出现（curl 首页无 baidu-site-verification 标签）→ **百度站长验证码未配置**（设置空则不输出，属未配置而非失效）。百度统计代码同理未检出。

**问题清单**：
1. 【确证bug】`process_seo_queue`（:752-774）含 4 处**裸 error_log**（`[YIMAI GSO] 开始处理推送队列`、`队列中有 N 篇文章待推送`、`正在推送文章 ID: x`、push_urls 内 :173/:189），完全不受 WP_DEBUG 控制。每次 cron 处理队列必写 5+ 条日志，自动发布高频站点日志无界增长。这正是主文件 yimai_gso_debug_log（:31-49 T3 修复注释）点名要收敛的行为，但 seo-pusher 未完成收敛。修复：改调 `yimai_gso_debug_log()`。
2. 【疑似】`push_urls`（:173-191）逐 URL 记日志时，三个平台的 success/fail 直接复制同一批结果到每条 URL——批量推送 50 URL 时日志表膨胀 50 倍且无法区分单 URL 失败；属数据精度问题。
3. 【建议】百度推送 API 常量 `API_BAIDU = 'http://data.zz.baidu.com'`（:17）为 http，官方支持 http/https 均可；无碍。

**结论：问题**（功能链路完整，但日志失控 1 条确证 bug）。

---

## 10. SEO 评分（includes/class-seo-scorer.php，1331 行）

**功能说明**：11 维度加权评分（标题合规13/关键词密度13/Meta描述10/内容深度15/图片优化10/内链建设10/标题结构7/可读性10/出站链接5/内容可提取性8(GEO)/内容新鲜度7(GEO)），AJAX 单篇分析/批量扫描/批量分析，结果存 `yimai_gso_seo_score` post meta。

**代码状态**：
- 评分维度与权重定义（:23-92 get_dimensions）；`analyze()`（:101-209）逐维评分并写缓存（:209）。
- AJAX 3 个 action 注册（主文件 :491-493）+ 前端调用（admin.js）✅ nonce + edit_posts 权限齐备。
- `ajax_batch_analyze`（:1221-1268）同时触发 GEO_Scorer::compute（:1252）——**这是 GEO 评分缓存（yimai_gso_geo_score meta）在全代码库唯一的自动写入路径**，且必须管理员在后台手动点「批量分析」。

**线上表现**：sitemap 中所有文章 `ai:ready=false` 且无 `ai:geo_score` 行 → 全站文章的 `yimai_gso_geo_score` post meta 为空或过期（GEO_Scorer::_xCSd 返回 null → geo_overall=0 → ai:ready 恒 false）。SEO 评分 meta（yimai_gso_seo_score）是否有点击过无法从外部验证，但 L5 仪表盘分布图依赖它（见第 16 节）。

**问题清单**：
1. 【确证bug】**SEO/GEO 评分无任何自动触发机制**：发布管线（publisher/autopilot-runner/seo-meta-optimizer/internal-link）零调用 SEO_Scorer/GEO_Scorer；评分只能靠管理员手动 AJAX 点击。后果链：① sitemap `ai:ready` 恒 false（AI 爬虫看到全站「未就绪」标记）② `ai:geo_score` 永不输出 ③ L5 仪表盘 SEO/GEO 分布图、散点图数据全空或陈旧 ④ GEO 缓存 24h 过期后（class-geo-scorer.php:492-509）所有下游读 null。修复建议：在 transition_post_status 链（优先级 30，内链完成后）或每日 cron 挂批量评分计算。
2. 【建议】`score_keyword_density` 等维度读 `yimai_gso_keywords`（:113-116），该键仅发布管线 set_zibll_meta 写入（class-publisher.php:559），手动发布的文章无此键 → extract_focus_keyword 兜底（:1272+）✅ 已兜底。

**结论：失效**（评分引擎本身代码健康，但无自动触发 = 功能「存在但从不运行」，下游 sitemap AI 标记与 L5 仪表盘全部失真）。

---

## 11. 标题分析（includes/class-heading-analyzer.php，1134 行）

**功能说明**：H1/H2/H3 结构分析（层级合理性/关键词覆盖/分布密度/问答式标题/标题-内容对应）、AI 优化建议与一键应用、GEO 标题分。

**代码状态**：
- 4 个 AJAX（analyze/optimize/apply_optimization/check_optimized）主文件注册（:469-472）✅ 前端全部调用 ✅
- `ajax_batch_scan`（:605）方法存在但**无注册无调用**——死方法（不影响功能，属冗余代码）。
- `analyze()` 读 `yimai_gso_keywords`（:41-48）有 extract 兜底。

**线上表现**：文章页 H2/H3 结构（curl 抽查）正常渲染多级标题；后台分析功能为交互式，代码链路完整。

**问题清单**：
1. 【建议】`ajax_batch_scan`（:605-651）从未注册/调用，46 行死代码；建议删除或补注册。
2. 【建议】`check_h1` 若主题已在页面模板输出 H1（文章标题），编辑器内正文 H1 会判重——常规设计已有处理。

**结论：正常**。

---

## 12. AI 摘要（ai-excerpt：class-publisher.php + 主文件 frontend_excerpt_display + 批量摘要 AJAX）

**功能说明**：三层能力：① 发布时自动 AI 摘要（`auto_excerpt` 参数 → `maybe_auto_generate_excerpt` → 异步 cron `yimai_gso_async_excerpt_generate`）；② 批量摘要后台工具（AJAX `yimai_gso_batch_generate_excerpt`/`excerpt_fetch_posts`）；③ 前端文章页摘要框展示（`frontend_excerpt_display` + CSS）。

**代码状态**：
- 单篇/批量生成表单传 `auto_excerpt` 勾选（admin.js:4750/4833）→ publisher publish() :238 → :379-380 触发 ✅
- **但永动发布（autopilot-runner.php:571-589）不传 `auto_excerpt`** → 自动发布文章永不走 ①；不过 autopilot 的 AI 文章数据自带 `excerpt`（:579 `'excerpt' => $article['excerpt'] ?? ''`）→ post_excerpt 直接非空 → 摘要来自文章生成阶段的 AI 输出，结果上仍有摘要。
- `auto_excerpt_on_publish` 设置键（publisher :44/:729 读取）**全代码库无任何写入点**——设置面板没有这个字段，开关恒为默认 0。即「全局自动摘要开关」是个死开关：单篇文章靠表单勾选、永动靠数据自带，这个 option 永远不会被打开。
- 批量摘要 AJAX（主文件 :455-456 注册 → :2395/:2526 实现）✅
- 前端摘要框：`frontend_excerpt_display`（主文件 :541-600）读 `frontend_excerpt_enabled`（默认 0）+ post_excerpt 兜底自动截断 250 字；CSS 注入 :602-647。

**线上表现**：
- 文章页 og:description/llms.txt 摘要内容 = AI 生成摘要已入库 ✅（发布管线写入）
- 线上文章页**未出现** `yimai-gso-excerpt-box`（前端摘要框未输出）→ `frontend_excerpt_enabled` 未开启或首页型主题模板未挂 the_content 主循环。查代码：摘要框仅 `is_singular('post')` 输出——文章页应满足；说明开关为 0（后台「基础设置 > 前端摘要显示」未勾选）或主题缓冲。属配置默认值，非 bug。

**问题清单**：
1. 【确证bug】`auto_excerpt_on_publish` 死设置键：读取 2 处（publisher:44/:729）、写入 0 处、设置 UI 0 处。代码含这条检查路径（全局自动摘要异步钩子 :23-70 也读它），但用户永远无法开启它——「保存了但没有任何代码写入的设置键」失效模式教科书案例。修复：在基础设置面板补 checkbox（fields 清单 class-admin.php:7035-7046 加一行 + form 增 checkbox），或删除该键统一走表单勾选。
2. 【建议】永动发布不传 auto_excerpt：若希望自动发布文章也走「独立 AI 摘要二次生成」，需补传；现状依赖 AI 文章自带 excerpt，可接受。

**结论：问题**（摘要数据链路正常，但存在一个无法开启的死开关）。

---

## 13. 图片 Alt 生成（includes/class-image-alt-generator.php，767 行）

**功能说明**：发布后自动补全图片 ALT（规则模式/AI 模式，transition_post_status 优先级 10 → 异步队列）；前端渲染时对缺 ALT 的历史图片兜底补全（the_content 999）；附件 ALT 同步（sync_attachment_alt_meta）。

**代码状态**：
- 构造器（:35-53）：`auto_image_alt_enabled`（默认 1 开启）/`auto_image_alt_mode` 读取；transition(10) + cron 队列 + the_content(999) 兜底 ✅
- 设置保存：class-admin.php:7037 fields 含 `auto_image_alt_enabled/mode` + :7080-7083 checkbox 未勾选置 0 特殊处理 ✅ 读写闭环。
- 批量 ALT AJAX（class-image-alt-admin.php）注册 + admin.js 调用 ✅

**线上表现**：文章页 6 个 img 标签全部有 alt，其中正文 AI 配图 alt 为完整描述句（「一麦瑜伽小班课学员在教练带领下同步练习，场地器械排列整齐」「教练在普拉提床旁指导学员调整体态，帮助感受发力变化」等）——AI 模式 ALT 真实生效且质量高 ✅ 首图 alt=文章标题 ✅ 主题装饰图（logo/二维码）alt 齐备 ✅

**问题清单**：
1. 【建议】og:image 与 Article schema image 使用同一张 AI 配图但 sitemap 的 ai:schema_types 只有 Article——一致性可，无问题。
2. 【建议】`auto_image_alt_enabled` 默认 1（隐式开启），与 SEO meta 默认 0 的保守风格不一致；现状线上已验证生效，无实际影响。

**结论：正常**（线上证据最强的模块之一）。

---

## 14. 内容新鲜度（includes/class-content-freshness.php，421 行）

**功能说明**：按 post_modified 计算新鲜度等级（fresh<30d/normal<180d/stale<365d/old），save_post 时计算缓存；「标记已审核」刷新时间戳（ajax_refresh_content → wp_update_post 改 post_modified）；批量标记、统计、列表 AJAX。

**代码状态**：
- 钩子：save_post(20) 计算（:37）+ 4 个 AJAX（:38-41）全部注册 ✅ 前端调用（admin.js:8492/8527/8705）✅
- `ajax_refresh_content`（:206-238）wp_update_post 修改 post_modified——⚠️ wp_update_post 传入 post_modified 会同时触发 save_post → on_save_post 重算（闭环正确）。
- 设置键无独立开关（始终启用），统计有 transient 缓存（yimai_gso_freshness_summary）。

**线上表现**：sitemap lastmod 显示最近文章 modified 时间实时更新（2026-10-26 当天）→ 新鲜度数据源健康；后台面板数据为内部 AJAX 无法外部验证。

**问题清单**：
1. 【建议】`ajax_refresh_content` 直接改 post_modified 而不产生内容变更（content unchanged but modified touched）——Google 视 lastmod 无实质内容变化可能忽略；「标记已审核」的 SEO 意义存疑，属产品语义问题非 bug。

**结论：正常**。

---

## 15. 内容检测（includes/class-content-detector.php，521 行）

**功能说明**：发布时对内容执行品牌/健康边界规则检测（违禁词/敏感表述替换、检测报告），transition_post_status(20) 同步执行。

**代码状态**：
- 构造器（:20-22）注册 transition(20)；`handle_transition_post_status`（:24-50）→ `process_published_post`（:91+）→ `detect_article`（:177+）规则匹配 → 直接替换/遮罩 → 报告存储 + 日志 ✅
- 无 AJAX（纯发布管线钩子），无死代码路径。
- 注意：与 SEO 直接关联较弱（内容合规层），审计范围内链路健康。

**线上表现**：无外部可验证端点；文章内容（curl）无违禁残留表述。

**问题清单**：
1. 【建议】`is_enabled()`（:53-56）读取 `yimai_gso_detection_enabled`（**默认 1 开启**），写入点在 class-admin.php:463（checkbox 保存）+ :1937（设置面板渲染）——**读写闭环完整，非死开关**（初判疑似撤销；与 auto_excerpt_on_publish 不同，此键有 UI 有保存）。注意 :54 注释与实际键名 `detection_enabled`（非 content_detector_enabled）一致 ✅。

**结论：正常**（设置闭环完整，默认开启）。

---

## 16. 爬虫追踪与诊断（class-crawler-tracker.php + class-crawler-diagnosis.php + class-crawler-dashboard.php + class-l5-dashboard.php + admin/js/l5-dashboard.js）

**功能说明**：shutdown 钩子识别 AI 爬虫 UA 并写 `geo_crawler_logs` 表；爬虫编辑器管理 robots UA 规则；L5 仪表盘聚合展示（总览/SEO分布/GEO分布/Schema覆盖/流量趋势/散点图/爬虫明细/诊断报告/预警）。

**代码状态**：
- Crawler_Tracker：shutdown(999)（:71）→ detect()（:82-103，UA 撞 KNOWN_CRAWLERS）→ log_visit（:225-247）写表；建表懒检查（ensure_table :162-168，1h transient 限频）✅ XSS 修复注释（S1，:198-223）质量高。
- Crawler_Editor：rules JSON 存储（:81-115）+ `yimai_gso_robots_crawler_block` filter（:73）→ 线上 robots.txt 26 个 UA 段 ✅（线上已验证）
- Crawler_Diagnosis：5 项检查（开放度/robots一致性/noindex/llms可访问/AI meta覆盖），AJAX run + robots_preview 注册（:40-41）✅ l5-dashboard.js 调用 ✅
- L5_Dashboard：8 个 AJAX 全部注册（:39-44）+ l5-dashboard.js 全部调用 ✅ nonce 用 `yimai_gso_ajax_nonce`（:39-44）
- ⚠️ **数据依赖死穴**：`ajax_seo_distribution`/`ajax_geo_distribution`（:528-567）查询 `yimai_gso_seo_score`/`yimai_gso_geo_score` post meta——而这两个 meta 只能靠管理员手动触发评分 AJAX 写入（见第 10 节）。L5 图表 = 空数据管道。

**线上表现**：robots.txt AI 爬虫段（爬虫编辑器输出）已验证 ✅；llms.txt 可访问（诊断项 4 会通过）✅；爬虫访问日志表数据无法外部验证。

**问题清单**：
1. 【确证bug】L5 仪表盘 SEO/GEO 分布图与散点图依赖手动评分数据（与第 10 节同根因）：自动发布的新文章永远不会出现在分布图里，直到管理员手动批量分析。仪表盘「总览」的评分均值同理。修复与第 10 节绑定（自动评分后此节自动恢复）。
2. 【建议】`maybe_log` 挂 shutdown(999)：页面缓存插件（如 WP Super Cache 静态化命中）直接发静态文件时 WP 不加载 → 爬虫访问漏记；若线上有 nginx fastcgi_cache/redis-cache 全页缓存，AI 爬虫命中缓存页不进 DB。建议在 nginx 层加 UA 日志或接受漏记。

**结论：问题**（追踪与诊断链路本身正常，但 L5 图表数据源断供——根因在第 10 节评分自动化缺失）。

---

## 17. SEO-GEO 桥接冲突检测（includes/class-seo-geo-bridge.php，1003 行）

**功能说明**：检测 Yoast/RankMath/SEOPress/AIOSEO 共存冲突（canonical 重复、Schema 重复、robots 冲突）、meta 一致性（SEO title vs Schema headline、description vs ai-description）、sitemap AI 增强（ai:ready/geo_score/schema_types/platforms）、自动修复（回填 ai-description 等）。

**代码状态**：
- 钩子：admin_init 检测 SEO 插件（:47）、wp_head(4) meta 一致性（:53，transient 1h 限频，HTML 注释警告）、sitemap entry filter（:56）✅
- 4 个 AJAX（get_conflicts/apply_fix/run_scan/get_status）注册（:60-63）+ admin.js 调用 ✅
- `enhance_sitemap_entry`（:454-502）：ai:ready 判定读 `GEO_Scorer::_xCSd`（缓存 only，不重算）→ 缓存空则 geo_overall=0 → **恒 false**。
- 冲突检测（detect_all_conflicts :553+）与 apply_fix（:789+）链路完整。
- ⚠️ `ajax_get_plugin_info`（:505）/`ajax_check_meta_consistency`（:519）方法存在但无 add_action 注册、无 JS 调用——死方法 ×2（冗余，无功能影响）。

**线上表现**：sitemap 每条 URL 带 `ai:ready=false` + `ai:schema_types` + `ai:platforms`（:464-501 输出结构验证）✅ 但 ai:ready 全 false、ai:geo_score 全缺失——第 10 节根因的下游表现。

**问题清单**：
1. 【确证bug】`enhance_sitemap_entry` 只读 GEO 评分缓存不触发计算（_xCSd null → 0），sitemap 的 ai:ready/ai:geo_score 因评分自动化缺失而全站恒 false/缺失（根因第 10 节；此处是放大器）。修复：评分自动化后此处自然恢复；或 sitemap 渲染时对 null 缓存触发 compute（批量场景性能需评估）。
2. 【建议】`ajax_get_plugin_info`/`ajax_check_meta_consistency`（:505/:519）死方法，建议删除或注册。
3. 【建议】wp_head(4) meta 一致性检查对每次首访文章 set_transient + update_post_meta（:298/:378）——高流量下首次访问的写放大（每小时每文章一次），可接受但可优化为纯 transient。

**结论：问题**（桥接自身逻辑健康，但 sitemap AI 增强数据被上游评分断供拖垮）。

---

## 18. 404 接管（旁证记录，供 f1 参考）

**线上取证**（curl 2026-10-26）：
1. 路径型 404（`/nonexistent-page-test-2026`、`/archives/99999999`）→ **nginx 原生 404**（146 字节 `nginx` 默认页，HTTP 404）。WordPress 完全未加载 → 插件的 `template_include`(999) 自定义 404 页（class-404-optimizer.php:873-889）、`template_redirect` 重定向接管（:736-775）、`log_404_hit`（:91-103，主文件 :437 注册）**全部不执行**。
2. 查询型 404（`?p=999999`）→ HTTP 404 但返回**与首页字节级完全相同的 HTML**（48930 字节，cmp 相同）——软 404：canonical 指向首页，对搜索引擎是「404 状态 + 首页内容」的矛盾信号。
3. 推断：宝塔 nginx 站点配置的 `error_page 404` 未回退到 WordPress（或伪静态规则把不匹配路径直接交给 nginx 静态 404），WP 的 404 模板管线（含插件接管）在路径型 404 上不可达。修复方向（供 f1）：nginx `error_page 404 /index.php`（或伪静态 try_files 正确兜底），保证 404 进入 WP → 插件 template_include/render_404_page 生效；同时设置项 `optimize_404_custom_enabled`（默认 0，class-404-optimizer.php:878）需开启。

**结论：失效（404 接管，供 f1 修复）**。

---

# 总结与优先级

## 失效（必须修）
| # | 模块 | 问题 | 定位 |
|---|------|------|------|
| 1 | SEO/GEO 评分 | 评分无自动触发，sitemap ai:ready 恒 false、L5 图表数据断供 | class-seo-scorer.php / class-geo-scorer.php / class-seo-geo-bridge.php:454 |
| 2 | 404 接管 | nginx 直接 404，WP 管线不可达（供 f1） | 服务器 nginx 配置 + class-404-optimizer.php:873 |

## 确证 bug（应修）
| # | 模块 | 问题 | 定位 |
|---|------|------|------|
| 3 | SEO 推送 | 5+ 处裸 error_log 不受控写日志 | class-seo-pusher.php:173,189,752-774 |
| 4 | 文章 title | 双站名拼接「…\| 一麦瑜伽·普拉提 – 一麦瑜伽」 | publisher write_seo_fields 或主题模板 |
| 5 | Schema | Article citation 提取噪声（内部小标题当引用 + 重复） | citation 生成逻辑 |
| 6 | Schema | BreadcrumbList name 未解码 HTML 实体（`&amp;`） | schema-generator breadcrumb 构建 |
| 7 | OG | 首页 og:image 为 SVG（微信/FB 不支持） | 主文件 output_og_meta:717-723 |
| 8 | Canonical | AJAX 双注册（扫描执行两遍） | 主文件:498-499 + class-canonical-manager.php:25-26 |
| 9 | AI 摘要 | `auto_excerpt_on_publish` 死设置键（无 UI 无写入） | class-publisher.php:44/:729 |
| 10 | AI 摘要 | `auto_excerpt_on_publish` 死设置键（无 UI 无写入） | class-publisher.php:44/:729 |
| 11 | llms.txt | 覆盖率缺口：14 篇文章仅列 6 篇（max_posts/排除分类设置问题） | class-llms-txt-generator.php:348-368 |

## 建议（可优化）
- llms-full.txt 无正文仅摘要（命名与行业语义不符，class-llms-txt-generator.php:187-232）
- llms.txt / llms-full.txt 覆盖率：14 篇文章仅列 6 篇（max_posts 设置或排除分类导致，后台核对）
- robots.txt 过度屏蔽 `/wp-includes/`、`/wp-content/plugins/`（影响搜索渲染）
- 死代码：heading-analyzer ajax_batch_scan（:605）、seo-geo-bridge 2 个死方法（:505/:519）
- L5 静态缓存页爬虫漏记（shutdown 钩子在 nginx cache 命中时不执行）

## 正常模块
Sitemap 生成 ✅、robots.txt 生成 ✅、SEO Meta/TDK 链路 ✅（title bug 除外）、Canonical ✅（双注册除外）、智能内链 ✅、标题分析 ✅、图片 Alt ✅、内容新鲜度 ✅、内容检测 ✅、爬虫追踪/编辑器/诊断 ✅（L5 图表数据断供除外）

llms.txt 归类说明：输出机制正常（第 3 节），但其「覆盖率缺 8 篇」已列入确证 bug 清单 #11（补充编号）：

## 修复优先级建议（供后续任务分配）
1. **P0**：评分自动触发（修复 sitemap AI 标记 + L5 图表，一次修复三处下游）
2. **P0**：404 nginx 回退配置（f1 任务）
3. **P1**：llms.txt 覆盖率（8 篇文章 AI 不可见）、SEO 推送日志收敛、title 双站名、citation 噪声、breadcrumb 实体解码、og:image SVG
4. **P2**：死开关补 UI（auto_excerpt_on_publish）、AJAX 双注册、死代码清理、llms-full.txt 命名语义

---

*审计方法说明：代码审查基于 v2.8.0 源码（44k 行，rg/grep/sed 精准定位）；线上取证 curl 于 2026-10-26 16:20-16:40 CST；AJAX 前后端闭环用 admin.js action 提取对照 wp_ajax_ 注册表全量比对（含 404 的 13 个 action）；设置键闭环用 rg 全库读写对照。*
