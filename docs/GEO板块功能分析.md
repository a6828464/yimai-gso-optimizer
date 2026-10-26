# GEO 板块功能分析报告

> 分析对象：一麦GSO双擎优化 v2.8.0（WordPress 插件）
> 分析日期：2026-10-26
> 分析人：geo-analyst（t2）
> 线上站验证基线：https://www.yimaiyoga.com/

## 0. GEO 功能地图（总览）

GEO（Generative Engine Optimization，生成引擎优化）指面向 AI 搜索引擎（ChatGPT Search / Perplexity / 豆包 / 文心一言 / Kimi 等）的内容可见性优化。插件 v2.8.0 的 GEO 板块由以下 8 个模块组成，按「数据流」分四层：

```
┌─────────────────────────────────────────────────────────────┐
│ 配置层（管理员填写）                                          │
│  ① GEO 实体（geo_entity_settings JSON option）               │
│  ⑥ 作者档案（wp_options/author profile 数据）                 │
│  ⑧ 知识中心（自定义数据表，品牌知识条目）                     │
├─────────────────────────────────────────────────────────────┤
│ 分析层（后台诊断/评分，不直接影响前台）                       │
│  ② GEO 评分（806 行，22 因子加权评分）                        │
│  ③ GEO 审计（706 行，22 因子逐项审计）                        │
│  ④ SEO-GEO 桥接（1003 行，读第三方 SEO 插件数据 + 冲突检测）    │
├─────────────────────────────────────────────────────────────┤
│ 内容层（写作时注入，影响最终文章正文）                         │
│  ⑤ 引用辅助（AI 写作时的引用溯源结构建议）                     │
│  ⑧ 知识中心 → augment_prompt/get_context/get_topic_seeds     │
├─────────────────────────────────────────────────────────────┤
│ 输出层（前台 HTML，AI 爬虫直接可见）                          │
│  ① GEO 实体 → 增强版 Organization JSON-LD（含 sameAs）        │
│  ⑥ 作者档案 → Person/ProfilePage JSON-LD（E-E-A-T）           │
│  ⑦ AI meta（<meta name="ai-description">/ai-keywords）       │
│  （辅助：Schema_Generator 的 Article/FAQ/Breadcrumb 等）      │
│  （辅助：LLMs_Txt_Generator 的 llms.txt 纯文本清单）           │
└─────────────────────────────────────────────────────────────┘
```

各模块与主文件挂载关系（yimai-gso-optimizer.php:344-377 init 序列）：

| # | 模块 | 文件 | 行数 | 实例化点 | 前台输出 |
|---|------|------|------|----------|----------|
| 1 | GEO 实体 | includes/class-geo-entity.php | 335 | :360 | ✅ wp_head:11 JSON-LD |
| 2 | GEO 评分 | includes/class-geo-scorer.php | 806 | :369 | ❌ 仅后台 meta_box |
| 3 | GEO 审计 | includes/class-geo-auditor.php | 706 | :370 | ❌ 仅后台 tab |
| 4 | SEO-GEO 桥接 | includes/class-seo-geo-bridge.php | 1003 | :377 | ❌ 仅后台联动 |
| 5 | 引用辅助 | includes/class-citation-helper.php | 395 | :368 | ⚠️ 写作期注入（间接） |
| 6 | 作者档案 | includes/class-author-profile.php | 238 | :362 | ✅ JSON-LD |
| 7 | AI meta | yimai-gso-optimizer.php:771-797 | — | :433 | ✅ meta 标签 |
| 8 | 知识中心 | includes/class-knowledge-center.php | 922 | :149 require + create_tables | ⚠️ 写作期注入（间接） |

> 注：`class-merged-geo-automation.php`（583 行）已于先前版本删除——钩子只在其构造函数注册而唯一 get_instance() 调用点是它自己的 activate()，从不被调用，属死代码模块（yimai-gso-optimizer.php:143-145 注释留档）。

## 1. GEO 实体（class-geo-entity.php，335 行）

### 做什么
定义品牌实体（名称/类型/Logo/描述/联系方式/地址）+ sameAs 身份外链（知乎/百度百科/微博/抖音/小红书/GitHub/LinkedIn/Wikipedia/天眼查/自定义），用于 Knowledge Graph 实体消歧，并输出增强版 Organization JSON-LD。这是 AI 搜索引擎「认出这个品牌是谁」的核心数据源。

### 数据流
```
设置→GEO实体 tab（class-admin.php:6448 render_geo_entity_settings）
  → AJAX yimai_gso_geo_entity_save（class-geo-entity.php:216 ajax_save）
  → save_entity_data()（:116）JSON 编码后存 option yimai_gso_geo_entity_settings
  → 前台 wp_head 优先级 11：output_enhanced_organization_schema()（:157）
  → 输出 <script type="application/ld+json"> 含 @type/name/url/description/logo/email/telephone/address/sameAs
  → AI 引擎抓取 JSON-LD 建立实体图谱
```

同页提供 sameAs 链接可达性检测：AJAX `yimai_gso_geo_entity_check_link`（:265）用 `wp_remote_head` HEAD 请求检测 HTTP 状态码。

### 代码状态
**整体正常，但有一处死代码与两处疑似问题：**

1. **【疑似·残留死代码】** ajax_save 中（class-geo-entity.php:225-227）：
   ```php
   // 权限检查
   if ( ! true ) {
       wp_send_json_error( array( 'message' => '当前配置不支持 GEO 实体优化功能，请升级配置' ) );
   }
   ```
   `if (! true)` 恒假，永不触发。这是原「付费配置门控」被本地化破解后留下的残骸（对应 admin 页 `$feature_ok = true;` class-admin.php:6449-6450 同源残留，`if (!$feature_ok)` 提示块同理永假）。功能不受影响，但建议 f2 清理。

2. **【疑似·互斥条件略宽】** `is_configured()`（:330-334）判定标准是 `! empty( $entity['same_as'] )`——**只要填了至少一条 sameAs 就算「已配置」**。后果：
   - 若用户只填了名称/电话/地址但没填 sameAs，增强版 Schema 完全不输出，前台退回到简版 output_organization_schema()（主文件 :806，只有 name/url/description/logo），填的联系方式和地址全部**静默丢失**，不进任何 JSON-LD。
   - 逻辑意图应是「实体信息填得足够多才接管」，但用 sameAs 一个维度判定，把 contact/address 与 sameAs 绑死了。
   
3. **【建议】** sameAs 校验 `filter_var(FILTER_VALIDATE_URL)`（:124）通过后未再限制协议，`javascript:` 等伪协议因含冒号可能绕过（WP esc_url_raw 在 ajax 层已过滤一次，双保险尚可，但 save_entity_data 独立调用时无此保护）。

### 线上实际效果（2026-10-26 curl 实测）
**增强版 LocalBusiness 节点已全站生效**（首页 HTML 第 60 行附近的 wp_head:11 输出）：name=一麦瑜伽、email=mengshunan@yimaiyoga.com、telephone=19906746828、address=双店合并一行、**sameAs 已配置 2 条**（小红书主页 + 抖音主页）。

这确认：① `is_configured()=true`（sameAs 非空）；② 保存链路正常（entity_type=LocalBusiness、联系方式、地址均落库）；③ entity_address 当前把两家店写在同一个字段里（streetAddress 拼接「东部店…；绿地店…」），语义上两个门店应各自一个 PostalAddress 节点——插件当前数据模型只有单一 entity_address 字段，属产品局限（见 C-2）。

首页另有 HealthAndBeautyBusiness ×2 的 @graph 块（两家门店各自完整实体，含独立电话/地址/联系人页 URL）——**该块不是本模块产出**（本模块输出无 @graph 结构、无分店字段），来源待归属（S-2）。两套「商家类」节点并存，字段口径基本一致，AI 引擎可合并，但 name 主键一致性需 f2 确认（「一麦瑜伽」vs「一麦瑜伽 · 东部店」是同族实体的子节点关系，合理）。

## 2. GEO 评分（class-geo-scorer.php，806 行）

### 做什么
**Read-Only Aggregator 模式**：不重新分析内容，只汇总 L1-L3 层已缓存的数据，对单篇文章按 GEO 5 维度加权打分（0-100）。5 维度（class-geo-scorer.php:32-50）：

| 维度 | 权重 | 数据来源 | 打分规则 |
|------|------|----------|----------|
| 答案前置 answer_firstness | 25% | `yimai_gso_seo_score` post_meta（SEO Scorer 的 detect_answer_first 缓存） | 直接复用缓存分 |
| 可提取性 extractability | 25% | `yimai_gso_extractability` post_meta | 直接复用 0-100 分 |
| Schema 覆盖 schema_coverage | 20% | `yimai_gso_schema_type` + `yimai_gso_custom_schema` post_meta | +40 有类型 / +20 非 Article / +20 高价值类型(FAQPage/HowTo/Product 等) / +20 自定义字段 |
| AI 爬虫友好 crawler_friendly | 15% | `yimai_gso_noindex` meta + Crawler_Editor 规则 + llms.txt transient | +40 可索引 / +30 爬虫全开放 / +15 llms.txt / +15 llms-full.txt |
| 实体覆盖 entity_coverage | 15% | `geo_entity_settings` option | +30 名称+类型 / +15 Logo / +15 描述 / +20 ≥2 sameAs / +40 ≥4 sameAs |

等级制：A(≥85)/B(≥70)/C(≥55)/D(≥40)/F（:468-480）。编辑页双引擎面板（SEO 分 + GEO 分对比）、分差 >15 触发「协同建议」（AI 生成或规则回退，:599-703）。

### 数据流
```
编辑页双引擎面板 → AJAX yimai_gso_dual_score（:497）
  → compute(post_id)（:82）：读缓存 _xCSd_internal（24h 过期，_xX4=86400）→ 逐维度 score_* → 加权 → post_meta yimai_gso_geo_score
  → save_post:30 invalidate_cache（:542 删缓存）
  → 分差>15 时 ajax_synergy_suggest → AI（generate_ai_synergy :680）或规则回退（get_rule_based_synergy :764）
```

### 代码状态
**功能正常，一处观察：**

1. **【疑似·设计问题】** score_answer_firstness（:156-186）依赖 `yimai_gso_seo_score` post_meta 的 `geo.answer_first` 子键——即用户**必须先跑过 SEO 综合评分**，GEO 分的 25% 才有数据；否则该项 0 分且提示「尚未执行 SEO 综合评分」。ajax_dual_score（:558-565）已做兜底（SEO 缓存为空时现场调 SEO_Scorer::analyze()），链路自洽，但纯查看 GEO 缓存（如审计）时仍可能读到 0。属引导设计而非 bug。

2. **【疑似·混淆项】** 实体覆盖度满分逻辑：+30(名称类型)+15(Logo)+15(描述)+40(≥4 sameAs)=100，数学自洽；但 sameAs 权重占 40%，与 auditor 的 E2 因子（sameAs ≥4 为优）一致，交叉印证设计意图：**sameAs 是 GEO 评分的核心杠杆**。若 sameAs 为空，实体维度最高只能拿 60%→整维度 15×0.6=9 分，直接拉低总分 ~6 分。

3. **【建议】** 常量命名 `_xX4`（缓存时长）与 `_xCSd/_xCSd_internal`（缓存读取）为混淆命名（疑似防扫描），可读性差，建议 v2.9.0 重命名。

4. **【建议】** generate_ai_synergy（:754）`$seo_weak[] = isset( $s['level'] ) ? $key : $key;` 两个分支同为 `$key`，三元表达式无意义（疑似编辑残留），可简化。

### 线上实际效果
不产生前台输出；评分结果存 post_meta，供双引擎面板与审计聚合使用。

## 3. GEO 审计（class-geo-auditor.php，706 行）

### 做什么
**整站级 AI 就绪度审计**（区别于 scorer 的单篇评分）：4 大类 22 因子（class-geo-auditor.php:26-60 FACTORS），报告存 option `yimai_gso_geo_audit_report`。面板入口：GEO优化 → AI就绪度审计。

| 类别 | 权重 | 因子 | 完成方式 |
|------|------|------|----------|
| 技术就绪度 technical | 25% | T1 HTTPS / T2 viewport / T3 HTML<150KB / T4 noindex<10% / T5 Canonical≥50% | 同步（audit_technical :163，wp_remote_get 首页实测） |
| 内容就绪度 content | 35% | C1 答案前置≥70占比≥50% / C2 可提取性≥50占比 / C3 过期<20% / C4 可读性 / C5 FAQ≥10% / C6 标题规范≥70% / C7 关键词≥50% | **WP-Cron 异步批处理**（cron_audit_content_batch :234，每批 50 篇） |
| 实体就绪度 entity | 20% | E1 实体配置 / E2 sameAs≥4 / E3 作者档案≥50% / E4 引用≥10% / E5 透明性≥30% / E6 分类≥20% | 同步（audit_entity :426） |
| 爬虫就绪度 crawler | 20% | R1 AI 爬虫未被禁（容忍≤3个，各扣15）/ R2 llms.txt / R3 30天爬虫活跃 / R4 sitemap | 同步（audit_crawler :499） |

finalize_report（:576）加权总分 + 按「实体>内容>爬虫>技术」顺序取最低分前 10 项为优先改进清单。

### 数据流
```
后台 geo-audit tab → AJAX yimai_gso_run_audit（:87）
  → 同步跑 technical/entity/crawler 三类 → 存中间报告
  → 内容审计走 wp_schedule_single_event('yimai_gso_audit_content_batch')（每批间隔 10 秒）
  → finalize_content_audit（:351）逐因子算通过率 → finalize_report 合并
  → AJAX yimai_gso_audit_status（:147）轮询进度/报告
```

### 代码状态
**功能正常，两处观察：**

1. **【疑似·指标口径问题】** C4「可读性」因子（:392-396）实际复用的是 `$agg['seo_scores']`（与 C1 答案前置**同一数组**！），判据同样是 `≥70 占比≥50%`——即 **C1 与 C4 完全同源同值**，注释虽写「使用 SEO 评分的可读性维度」但代码并未取可读性子维度。这使 content 类 7 因子实际只有 6 个独立信息源，可读性因子名存实亡。**f2 建议改为读取 `yimai_gso_seo_score` 的可读性子键**（若 SEO Scorer 有 readability 维度缓存）。

2. **【确证·已修复注释留档】** E5 count_transparent_posts（:696-699）注释说明原查询 `yimai_gso_ai_assisted` 无写入端永远为 0，已改查 `yimai_gso_generated`。口径正确（publisher 写入）。同类历史修复见 C2 阈值从 70 调到 50（:366 注释）。这些是先前修复的良好范例，说明 auditor 曾有一轮「假数据因子」清理。

3. **【疑似·批处理脆弱点】** cron_audit_content_batch 依赖 WP-Cron 链式自调度（:331 `wp_schedule_single_event(time()+10)`）——若站点禁用 WP-Cron（改为系统 cron 且未触发 `yimai_gso_audit_content_batch` hook）或并发触发丢事件，批处理可能中途停摆，报告 status 永远停在 running。ajax_run_audit 有防并发清理（:95-99）但无「批处理超时看门狗」。观测站（yimaiyoga）用宝塔系统 cron 时尤其要注意。

4. **【建议】** audit_technical T2/T3 用 `wp_remote_get(home_url('/'))` 自请求首页（:175-179）——站内请求自身在部分反代/缓存配置下可能拿到缓存页或被 WAF 拦，导致 viewport 检测误判；已有 10s 超时兜底，可接受。

### 线上实际效果
不产生前台输出；审计报告驱动后台「优先改进清单」，是站长侧 GEO 运营的指挥台。

## 4. SEO-GEO 桥接（class-seo-geo-bridge.php，1003 行）

### 做什么
L6 桥接层，三个子能力（class-seo-geo-bridge.php:8-15）：
1. **SEO 插件深度集成**：检测 Yoast（WPSEO_VERSION 常量）/Rank Math（RankMath 类）/SEOPress/AIOSEO 是否安装，transient 缓存 1 小时（detect_seo_plugins :71，DETECT_TRANSIENT）；
2. **Meta 标签同步管理**：wp_head 优先级 4（早于本插件 og/twitter/ai-meta 的 5）检查三方 meta 一致性——SEO description ↔ ai-description 回退、SEO title ↔ Schema headline、keywords ↔ Schema about、author ↔ Schema author（synchronize_meta_output :282 → check_meta_consistency :319），不一致时前台输出 HTML 注释警告（:303-309，仅注释不影响渲染）+ 写 post_meta `yimai_gso_meta_consistency_check`；
3. **冲突检测与修复**：detect_all_conflicts（:553）五类冲突——Schema 重复（调 Schema_Generator::detect_conflict_plugins）、Canonical 重复、AI 描述缺失可自动回填（count_missing_ai_descriptions :659 直查 wpdb 三表 LEFT JOIN）、robots.txt 协同、插件兼容性总览；AJAX ajax_run_scan/ajax_apply_fix（:891/:869）驱动后台「桥接管理页」。

另：enhance_sitemap_entry（:454）给 sitemap 每个 `<url>` 追加 GEO 标记行；extend_with_seo_plugin_data（:152）挂 `yimai_gso_synergy_context` filter，把 SEO 插件数据注入 GEO Scorer 的协同建议上下文。

### 数据流
```
admin_init → detect_seo_plugins（写 $this->detected_plugins + transient）
前台 wp_head:4 → synchronize_meta_output（is_singular 限流 1h/post）→ 一致性警告注释
GEO Scorer ajax_synergy_suggest → filter yimai_gso_synergy_context ← extend_with_seo_plugin_data
后台桥接页 → ajax_run_scan → detect_all_conflicts（CONFLICT_TRANSIENT 缓存 1h）
        → ajax_apply_fix → apply_fix（:789，如 AI 描述批量回填）
Sitemap 生成 → apply_filters(yimai_gso_sitemap_entry_filter) ← enhance_sitemap_entry
```

### 代码状态
**功能正常，两处观察：**

1. **【疑似·优先级链不一致】** read_legacy_ai_meta（:429-445）注释称「与 output_ai_meta() 同字段优先级链」，但实际顺序不同：
   - output_ai_meta（主文件 :776-779）：`yimai_gso_excerpt → _yoast_wpseo_metadesc → rank_math_description → description`
   - read_legacy_ai_meta（:432-435）：`yimai_gso_excerpt → description → _yoast_wpseo_metadesc → rank_math_description`
   `description` 的位置不一致。空值回退下多数场景结果相同，但当 `_yoast_wpseo_metadesc` 为空而 `description` meta 有值且同时有 rank_math_description 时，两者会取到不同值——一致性检查的「基准」与前台实际输出可能不同源，造成误报/漏报。**f2 建议统一为与 output_ai_meta 相同顺序**。

2. **【疑似·检测前置条件】** count_missing_ai_descriptions（:659-686）SQL 只查 `_yoast_wpseo_metadesc` 与 `rank_math_description` 两个 SEO 插件的 meta，SEOPress（`_seopress_titles_desc`）与 AIOSEO（`_aioseo_description`）不在查询中——检测覆盖面与「支持四插件」的宣称不完全对齐。检测到 SEOPress/AIOSEO 时该统计恒为 0。

3. **【建议】** synchronize_meta_output 每篇文章每 1 小时向 wp_options 写一个 transient（`yimai_gso_meta_check_rate_{id}`），文章数多时 transient 表膨胀（虽然 WP 会过期清理，但对象缓存未启用时写 wp_options）。可改为按日聚合或 nonce 化。

4. **【建议】** check_meta_consistency 每次前台访问都可能 update_post_meta（:359-361）——前台请求写 DB（在限频窗口外）。Redis-cache/object-cache 部署下无碍，但纯 WP 安装下是「访客触发的写」，流量高时值得改成仅后台/CLI 触发。

### 线上实际效果
线上未装 Yoast/RankMath（实测 HTML 无 yoast/rankmath 痕迹），is_any_seo_plugin_active()=false：canonical 冲突检测跳过、AI 描述统计跳过、插件兼容性总览跳过。synchronize_meta_output 的本地三方一致性检查（yimai_gso_seo_title vs schema_headline 等）仍然生效。桥接处于静默待命状态。

## 5. 引用辅助（class-citation-helper.php，395 行）

### 做什么
E-E-A-T 引用溯源信号（class-citation-helper.php:8-13）：
1. 自动检测文章三类引用：硬数据（数字+单位，DATA_PATTERN :34）、命名引用（「根据/研究表明/调查显示」等 17 个引导词 :27-33）、URL 引用（排除本站链接 :142）；
2. 来源分级（classify_tier :225）：一级权威（.gov/.edu/who.int 等）> 二级媒体（新华网/人民网/财新/路透等）> 三级其他；
3. 评分（:160-172）：数据引用+30 / URL引用+25 / 命名引用+25 / ≥3处+10 / ≥5处+10，满分 100；
4. 结果写 post_meta `yimai_gso_citations`，供 GEO 审计 E4 因子（引用≥10% 文章占比）与编辑侧栏反馈。

### 数据流
```
save_post:25 schedule_analysis（:57）→ shutdown process_scheduled（:64，延迟到请求收尾避免阻塞保存）
  → analyze(post_id)（:80）：正则三类检测 → 去重 → 评分 → update_post_meta yimai_gso_citations
前台：Schema_Generator:198-225 把 citation 数组并入主 Article 节点的 citation 属性
     （修复注释留档：原先输出独立 @type:Article 节点与主 Article 重复，已改为并入）
写作期：AI prompt 中的引用结构建议（generate_tips :365）
```

### 代码状态
**功能正常，一处死代码：**

1. **【确证·死代码】** `generate_schema_json_ld()`（:251-289）——rg 全库搜索仅定义处出现，**无任何调用点**（前台输出已由 schema-generator.php:198-225 内联重写实现，旧方法遗留）。连带 `is_valid_citation_name()`（:290）也仅被这个死方法调用。**注意**：内联重写版（schema-generator:208-218）的过滤比旧版宽松——只查 name/url 非空，未复用 is_valid_citation_name 的「纯数字/量词/碎片文本」过滤，意味着「5个」「，优化全靠玄学」这类碎片可能重新混进 citation 数组。f2 修复建议：**让 schema-generator 内联逻辑复用 is_valid_citation_name()**，然后删除 generate_schema_json_ld 死方法。

2. **【确证·未挂载 AJAX】** `ajax_get_citations()`（:341）定义了但**未注册任何 wp_ajax_ 钩子**（构造函数只挂 save_post/shutdown）——rg 搜索 `yimai_gso_get_citations` 无 add_action。若编辑侧栏通过此 action 请求数据会得 400。需查 admin JS 实际调用名（f2 核实：可能侧栏用的是别的 action 或根本没做前端）。

3. **【疑似】** schedule_analysis 用静态 $pending + shutdown 钩子（:53-68）——WP 单请求生命周期内有效，但 save_post 后若有 wp_redirect（如发布后跳转）shutdown 仍会执行，逻辑成立；但若在 REST/Facebook Instant 类上下文 shutdown 早于 analyze 完成则丢分析。边缘场景，风险低。

### 线上实际效果
文章页 Article JSON-LD 的 citation 属性（若该文有被检出的引用）。属间接 AI 可见性（引用结构提升 AI 引擎对内容可信度评估）。

## 6. 作者档案（class-author-profile.php，238 行）

### 做什么
E-E-A-T 作者实体（class-author-profile.php:8-13）：
1. 扩展 WordPress 用户资料页 5 个 GEO 字段：职务/头衔、擅长领域、从业年限、资质证书、sameAs 社交链接（render_profile_fields :48）；
2. 作者存档页（is_author）wp_head:12 输出 Person JSON-LD（output_author_person_schema :147），含 jobTitle/knowsAbout/sameAs（build_author_person :176）；
3. AJAX yimai_gso_save_author_profile 供后台作者档案管理页保存（ajax_save_profile :215）。
user_meta 键：`yimai_gso_author_title/expertise/years/certifications/sameas`。

保存后 `delete_option('yimai_gso_geo_audit_report')`（:143/:234）——作者档案变更自动清审计缓存，避免 E3 因子显示旧结果（设计良好）。

### 数据流
```
用户资料页/后台作者档案页 → save_profile_fields（personal_options_update/edit_user_profile_update）
  → user_meta yimai_gso_author_* 
  → 作者存档页 wp_head:12 → Person JSON-LD
  → 文章页：Schema_Generator build_person（class-schema-generator.php:654）读 author 数据生成 Article.author Person 节点
  → GEO 审计 E3 因子（count_authors_with_profile 查 3 个 usermeta 任一非空，class-geo-auditor.php:681-686）
```

### 代码状态
**功能正常，一处观察：**

1. **【疑似·字段未充分利用】** 「从业年限」`yimai_gso_author_years` 与「资质证书」`yimai_gso_author_certifications` 两个字段**保存了但 build_author_person 不读取**（:176-213 只用 title/expertise/sameas）——schema.org Person 也没有直接的「从业年限」属性，但 years 可映射 `experience` 相关结构或并入 description；certifications 可映射 `hasCredential`（schema.org/hasCredential 是 Person 合法属性）。当前这两个字段**只喂给审计 E3 因子计数**（任一非空就算有档案），对前台 AI 可见性零贡献。f2 可考虑在 Person JSON-LD 补 hasCredential。

2. **【建议】** output_author_person_schema 仅 is_author() 输出独立节点；文章页的 Person 靠 schema-generator 的 Article.author 内嵌。两处 Person 的字段丰富度不同（author 存档页含 jobTitle/knowsAbout，文章页内嵌版需查 build_person 实现是否也带这些 GEO 字段——f2 核对 class-schema-generator.php:654-680）。

### 线上实际效果
✅ **实测已生效**：首页 block 4（WebPage #eeat 节点）内嵌 `author: Person "shunan"` + `publisher: Organization "一麦瑜伽"` 且 **publisher.sameAs 含小红书+抖音链接**——即线上实体的 sameAs 已配置 2 条（小红书/抖音）。这确认 `is_configured()=true`，增强版 Organization 输出路径激活。

## 7. AI meta 输出（主文件 output_ai_meta :771-797）

### 做什么
向 AI 引擎声明内容摘要与关键词——输出 `<meta name="ai-description">` 与 `<meta name="ai-keywords">`（非标准 meta，属插件自定义约定，部分 AI 爬虫会参考；Google 忽略未知 meta 不受影响）。

### 数据流
```
wp_head 优先级 5（早于 og/twitter）
  → 取 yimai_gso_excerpt → Yoast meta → RankMath meta → 自动截断 160 字
  → echo <meta name="ai-description"> / ai-keywords
```

### 代码状态
**【确证 bug】仅 `is_singular('post')` 生效**（:772）：
- 页面（page）、自定义 post type（如瑜伽课程 CPT 若存在）**不输出任何 AI meta**；
- 首页/列表页无 AI meta（首页往往恰是 AI 引擎问「这个站是干嘛的」时抓的页面）。
- 影响：AI meta 覆盖面严重受限。f2 可扩展 `is_singular()` 或按设置白名单 post types。

另注意 ai_desc 回退 `wp_trim_words(..., 30, '')` 后截 160 字符（:784）——中文 30 词 ≈ 60 字符，实际通常提前触底，属实现细节非 bug。

### 线上实际效果
✅ **文章页已验证**（/archives/1281 实测）：`ai-description` 与 `ai-keywords` 均正常输出，内容与 description meta 基本同源。❌ 首页无（因 is_singular('post') 拦截）——但首页由 AI Platform Adapter 的 `ai-platforms` 系列 meta 补位（见 ⑩）。

### 关联发现：AI Platform Adapter（L6，任务范围外但前台 AI meta 的实际大户）
线上实测发现 wp_head 还输出了另一组 GEO 相关 meta（`<meta name="ai-platforms" content="chatgpt,gemini,deepseek,doubao,claude,perplexity">`、`ai-content-format`、`ai-schema-coverage`、`ai-citation-available`、`ai-entity-type`、`ai-platform:chatgpt` 等 11 个标签），来源是 class-ai-platform-adapter.php（:200-241 output_platform_meta，wp_head:12）——按 AI 平台（ChatGPT/Gemini/DeepSeek/豆包/Claude/Perplexity）注册的个性化 schema 优先级与内容格式声明。另有 wp_head:13 output_platform_schema（:243-297）输出平台专属 JSON-LD：
- claude 激活时：WebPage #citations 节点（build_claude_citation_schema :305，引用列表 mainEntity 回指主 Article）；
- gemini 激活时：WebPage #eeat 节点（build_gemini_eeat_schema :376，author Person + publisher Organization.sameAs 从 GEO Entity 读取）；
- perplexity 激活时：WebContent #references 节点（build_perplexity_citation_schema :343）。

线上首页 block 3/block 4 的 WebPage #citations/#eeat 节点即此模块产出（claude+gemini 均已激活）。**注意**：build_claude_citation_schema 读取的 post_meta 键是 `yimai_gso_citation_data`（:312），而 Citation_Helper 写入的键是 `yimai_gso_citations`（class-citation-helper.php:178）——**键名不匹配**，导致 citation_data 恒为空，citations 只含「文章自身」兜底项。这是【确证 bug】，列入问题清单 B-5。

## 8. 知识中心对 GEO 的贡献（class-knowledge-center.php，922 行）

### 做什么
品牌知识库：两张自定义表 `wp_yimai_gso_knowledge_documents`（含 visibility 公开/内部/受限/屏蔽 + risk_level + status）+ `..._knowledge_chunks`（1200 字分块 + heading + keywords）。三大 GEO 注入通道：

1. **augment_prompt()（:814-849）**——AI 写作提示词增强。核心防幻觉设计：
   - 硬约束声明「只能使用资料中明确存在的事实，不得补造地址、价格、资质、人物、案例或效果数据」；
   - 品牌边界「不得把其他瑜伽、健身或培训品牌写成推荐对象」；
   - 注入 **canonical 实体**（从 GEO Entity 读 entity_name/type/description/address，与 ① GEO 实体模块联动！）；
   - 逐条注入知识块 `<source id="kb-N" visibility="..." title="...">`；
   - 即使检索 0 命中，也保留 canonical 实体与品牌边界（:848 注释）。

2. **get_context()（:730-792）**——关键词检索（preg_split 8 词上限 → 4 列 LIKE OR → 打分 title/heading×6 + content×1 → 排序截断 CONTEXT_CHARS）。LIKE 检索非向量检索，中文短词会命中较多噪声，但配合打分可接受。

3. **get_topic_seeds()（:793-812）**——从 documents 表取 title 做选题种子（autopilot-plan.php:111 调用，`get_topic_seeds(30)`），保证自动发布的选题围绕品牌知识领域。

### 数据流
```
知识中心管理页（admin_post 导入/更新/删除，:31-34）→ documents/chunks 表
AI 单篇生成 class-ai-generator.php:40 → augment_prompt（批量链路）
AI 生成 class-ai-generator.php:1066 → augment_prompt（单篇链路，注释明确「必须显式接入」）
自动选题 class-autopilot-plan.php:111 → get_topic_seeds(30)
  → 品牌事实进入文章正文 → 正文被 AI 引擎抓取 = GEO 生效
```

### 代码状态
**功能正常，是 GEO 板块设计最扎实的模块**。观察：

1. **【正常·双链路覆盖】** augment_prompt 有两个调用点（:40 批量 generate_article、:1066 单篇链路），单篇链路注释明确「必须显式接入 GEO 实体和品牌资料上下文」——历史 bug 修复留档。system_prompt 尾部还追加了品牌硬约束（ai-generator:53），双保险。

2. **【疑似·管理员数据泄漏面】** get_context 的 SQL 过滤 `visibility IN ('public','internal')`（:758）——**internal（内部）资料会进入 AI API 调用的 prompt**（有「内部参考资料只能用于理解，不得逐字公开」的提示词约束，但 LLM 并非 100% 服从）。若知识库含价格表/会员数据等敏感内部资料，存在经 AI 生成内容外泄的理论通道。risk_level='blocked'/visibility='restricted' 被排除，设计已有分级，但 internal 分级的管理员意识需到位。**这是运营纪律问题而非代码 bug。**

3. **【建议】** LIKE 检索 `%关键词%` 在表大时会全表扫（documents 表 KEY 索引帮不上 LIKE %x%）。当前知识库量级（瑜伽馆几十份文档）完全无碍，量级上千后建议加全文索引或向量检索。

### 线上实际效果
间接生效：线上文章（如「一麦瑜伽减脂塑形课怎么上？」等）的正文品牌事实一致性由本模块保障；llms.txt 的品牌描述与文章口径一致也是本模块 + canonical 实体的产出（见 ⑩ 线上验证）。

---

## 10. 线上实际效果汇总（2026-10-26 curl 实测 https://www.yimaiyoga.com/）

### 首页 JSON-LD 全景（5 个块，对应产出模块逐一归属）

| 块 | @type | 产出模块 | 内容摘要 |
|----|-------|----------|----------|
| 0 | HealthAndBeautyBusiness ×2（@graph） | **待归属**——两家门店（东部店/绿地店）电话+地址，疑似主题/区块功能或 schema-generator 自定义，非 class-geo-entity.php 产出（该类输出无 @graph 结构）| f2 需确认来源（可能是主题或店铺区块） |
| 1 | WebSite（含 SearchAction/potentialAction） | Schema_Generator output_front_page_schemas | 站名+描述+搜索动作，格式带 `\/` 转义（wp_json_encode 未加 UNESCAPED_SLASHES 的路径） |
| 2 | LocalBusiness（含 email/telephone/PostalAddress/sameAs×2） | **GEO Entity output_enhanced_organization_schema**（wp_head:11）| name 一麦瑜伽、email mengshunan@yimaiyoga.com、双店地址合一、**sameAs=小红书+抖音 2 条** |
| 3 | WebPage #citations（citation 数组） | AI Platform Adapter build_claude_citation_schema（claude 激活）| citation 仅含文章自身（B-5 bug：citation_data 键名错） |
| 4 | WebPage #eeat（author Person + publisher Organization.sameAs×2） | AI Platform Adapter build_gemini_eeat_schema（gemini 激活）| publisher.sameAs 与 GEO Entity 同源（小红书+抖音） |

**首页 meta 层**：`ai-platforms`/`ai-content-format`/`ai-schema-coverage`/`ai-citation-available`/`ai-entity-type`/`ai-platform:chatgpt` 等 11 个 meta（AI Platform Adapter output_platform_meta，wp_head:12）；og:title/og:description 正常。**无 ai-description/ai-keywords**（is_singular('post') 拦截，见 B-1）。

### 文章页（/archives/1281）JSON-LD 全景（5 个块）

| 块 | @type | 产出模块 | 内容摘要 |
|----|-------|----------|----------|
| 0 | BreadcrumbList（3×ListItem） | Schema_Generator | 面包屑正常 |
| 1 | Article（headline/description/author Person/publisher Organization+logo/mainEntityOfPage/keywords/image/citation×2） | Schema_Generator build_article + Citation 并入 | **citation 数组含重复项**：「学员当天的状态给针对性指导」×2（去重键 md5(source+text) 因 source 与 text 组合差异未生效或内联重写无去重——f2 核对） |
| 2 | LocalBusiness（同首页块 2） | GEO Entity 增强版 | 全站统一输出 |
| 3 | WebPage #citations | AI Platform Adapter（claude） | citation=文章自身 |
| 4 | WebPage #eeat | AI Platform Adapter（gemini） | author+publisher.sameAs |

**文章页 meta 层**：`ai-description`（与 description 同源文本）、`ai-keywords`（6 个关键词逗号分隔）均正常输出 ✅。

### robots.txt / llms.txt / sitemap（爬虫就绪度实测）

- **robots.txt**：由插件生成（`# Generated by 一麦GSO双擎优化`），`User-agent: * Allow: /`，wp-admin 等常规禁爬，**13 个 AI 爬虫全部显式 Allow**（GPTBot/ChatGPT-User/OAI-SearchBot/Google-Extended/ClaudeBot/PerplexityBot/DeepSeekBot/Bytespider/DoubaoBot/BingBot/Microsoft-Edge-Bot/Baiduspider-mobile 等）——GEO 爬虫友好度满分（审计 R1 因子应通过）；
- **llms.txt**：HTTP 200 ✅，含品牌描述/核心页/4 个分类/近期 6 篇文章（LLMs_Txt_Generator 产出），robots.txt 中有 `Sitemap: .../llms.txt` 引导；
- **llms-full.txt**：HTTP 200 ✅；
- **sitemap.xml**：站点地图索引含 post/category/post_tag 三张子图，均带 lastmod（审计 R4 应通过）。

### GEO 实体 sameAs 现状
线上增强 LocalBusiness 节点 **sameAs 已配置 2 条**：小红书 + 抖音。对照 GEO 评分 entity_coverage（≥2 条 +20 分）与审计 E2 因子（≥4 条为优）——**当前 2 条达到「可用」但未达「优」**，建议补齐（见使用建议）。

### 结论：线上 GEO 输出链路总体健康
8 个分析模块中，前台实际产出链路（GEO Entity → JSON-LD、AI meta、AI Platform Adapter meta/schema、Schema_Generator 主体、llms.txt、robots.txt）全部在线上验证生效。问题集中在**质量细节**（citation 重复/兜底化、C4 因子同源、覆盖面受限）而非链路断裂。

## 9. 问题清单（供 f2 修复参考）

### 【确证 bug】（代码+线上双验证）
| # | 位置 | 问题 | 修复建议 | 优先级 |
|---|------|------|----------|--------|
| B-1 | yimai-gso-optimizer.php:772 | output_ai_meta 仅 is_singular('post')，页面(page)/CPT/首页全不输出 ai-description/ai-keywords（线上首页已验证缺失） | 扩展为 is_singular() 或 post type 白名单设置；首页可输出站点级 AI 描述 | 高 |
| B-2 | class-geo-entity.php:330-334 is_configured() | 判定标准仅看 sameAs 非空——实体名称/电话/地址已填但 sameAs 空时增强 Schema 完全不输出，已填字段静默丢失 | 改为「entity_name/entity_logo/entity_description/entity_email 等核心字段有实质填写即 true」，sameAs 只是加分项 | 高 |
| B-3 | class-geo-entity.php:225-227 `if(!true)` | 恒假死代码（付费门控破解残留），永不触发的报错分支 | 删除 | 低 |
| B-4 | class-admin.php:6449-6460 `$feature_ok=true` + `if(!$feature_ok)` | 同源门控残留：提示块「GEO 实体优化功能已由本地版本启用」永假不渲染，disabled 属性永假 | 清理整个 $feature_ok 变量与条件块 | 低 |
| B-5 | class-ai-platform-adapter.php:312 | build_claude_citation_schema 读 post_meta `yimai_gso_citation_data`，但 Citation_Helper 写入键是 `yimai_gso_citations`——**键名不匹配**，citation 永远只含「文章自身」兜底项，真实引用数据喂不进 Claude 块（线上 /archives/1281 已验证：#citations 节点仅 1 项） | 改读 `yimai_gso_citations` 并适配其结构（{count, sources:[{source,url,...}]}），复用 is_valid_citation_name 过滤 | 高 |
| B-6 | class-citation-helper.php:251-289 | generate_schema_json_ld() 全库无调用点（死方法），且 schema-generator:198-225 内联重写版**未复用** is_valid_citation_name 过滤——线上 Article.citation 出现重复项「学员当天的状态给针对性指导」×2（source/text 去重键碰撞 + 碎片文本混入） | 删除死方法；schema-generator 内联逻辑复用 is_valid_citation_name() 并按 url+name 去重 | 中 |
| B-7 | class-citation-helper.php:341-363 | ajax_get_citations() 定义但未注册 wp_ajax 钩子（构造函数只挂 save_post/shutdown）——前端若调用将 400 | 查 admin JS 实际调用名后补注册或删方法 | 中 |
| B-8 | class-geo-auditor.php:392-396（C4 因子） | C4「可读性」复用 C1 同一数组 $agg['seo_scores'] 同一判据——7 因子实际只有 6 个独立信息源，可读性因子名存实亡 | 改读 SEO 评分的 readability 子键（需核对 SEO_Scorer 是否缓存该维度） | 中 |

### 【疑似】（需 f2 进一步确认）
| # | 位置 | 问题 |
|---|------|------|
| S-1 | class-geo-entity.php:118-128 | sameAs 只校验 FILTER_VALIDATE_URL 未限协议；ajax 层有 esc_url_raw 双保险，但 save_entity_data 独立调用路径无此保护 |
| S-2 | 线上首页 | 首页组织类节点既有 LocalBusiness（GEO Entity :11）也有 HealthAndBeautyBusiness×2（@graph，来源待归属）——三个「商家类」节点并存。名字/地址一致则 AI 引擎可合并，但 f2 应确认 @graph 块的产出者（主题？区块？schema-generator 自定义？）并在文档中归位 |
| S-3 | class-seo-geo-bridge.php:432-435 vs 主文件:776-779 | read_legacy_ai_meta 回退链顺序 `excerpt→description→yoast→rankmath` 与 output_ai_meta 实际链 `excerpt→yoast→rankmath→description` 不一致——一致性检查的「基准」与前台实际输出可能不同源，产生误报/漏报 |
| S-4 | class-seo-geo-bridge.php:659-686 | count_missing_ai_descriptions 只查 Yoast/RankMath 的 meta 键，SEOPress（_seopress_titles_desc）/AIOSEO（_aioseo_description）未覆盖——宣称支持四插件但统计只认两家 |
| S-5 | class-geo-auditor.php:331（cron 链式自调度） | content 审计批处理靠 wp_schedule_single_event(time()+10) 链式接力——宝塔系统 cron 场景下若 hook 触发遗漏，审计 status 永停 running，无看门狗 |
| S-6 | class-geo-scorer.php:156-186 | score_answer_firstness 依赖先跑过 SEO 综合评分；纯查看路径（不触发 ajax_dual_score 的兜底）读到 0 分——引导设计而非 bug，但 UX 上易误判「内容差」 |
| S-7 | 主文件 :784 | ai_desc 回退 wp_trim_words(30)≈60 中文字符后截 160，实际提前触底，meta 描述偏短 |
| S-8 | class-author-profile.php:176-213 | 从业年限(yesterday years)/资质证书(certifications) 两个字段保存后不进 Person JSON-LD——schema.org 有 hasCredential 属性可用；当前两字段仅喂审计 E3 计数 |
| S-9 | class-seo-geo-bridge.php:282-310 | synchronize_meta_output 前台访客触发写 transient+post_meta（限频 1h/post），高流量下是访客触发的 DB 写 |
| S-10 | class-knowledge-center.php:758 | get_context 含 internal 可见性资料进 AI prompt——「不得逐字公开」仅是提示词约束，LLM 非强约束；敏感内部资料有理论外泄通道（运营纪律问题） |

### 【建议】（改进项，非 bug）
| # | 位置 | 建议 |
|---|------|------|
| C-1 | class-geo-entity.php:265-292 | ajax_check_link 用 HEAD 请求，知乎/微博等平台对 HEAD 返回 405/403，建议失败回退 GET |
| C-2 | class-geo-entity.php:41-48 + :194-200 | ENTITY_TYPES 无 HealthAndBeautyBusiness 选项，且 address 仅 LocalBusiness 输出——瑜伽馆行业的标准类型恰是 HealthAndBeautyBusiness（线上另一节点已用），建议补选项或 address 输出放宽到 LocalBusiness 子类型族 |
| C-3 | class-geo-scorer.php:56-59 | 常量 _xX4/_xCSd 混淆命名（疑似防扫描遗留），建议 v2.9.0 重命名 CACHE_TTL/get_cached_score |
| C-4 | class-geo-scorer.php:754 | `$seo_weak[] = isset($s['level']) ? $key : $key;` 两分支同值，三元无意义（编辑残留） |
| C-5 | 多文件 | @since 标注混乱：class-geo-entity.php:15 写 2.9.0、class-seo-geo-bridge.php:14 写 3.0.0、class-citation-helper.php:11 写 2.8.1，当前实际 v2.8.0——v2.9.0 发布时统一 |
| C-6 | class-seo-geo-bridge.php:429-445 | read_legacy_ai_meta 的 keywords 回退链可同步考虑 title 场景（当前只 description/keywords 两组） |

### 待 f2 线上复核清单（本报告已完成的验证打勾）
- [x] 线上 sameAs 已配置（小红书+抖音 2 条，entity_type=LocalBusiness——从增强节点 @type 反推）
- [x] 单篇文章页 ai-description/ai-keywords meta 正常输出（/archives/1281 验证 ✅）
- [x] 首页无 ai-description（B-1 确证）
- [x] robots.txt 13 个 AI 爬虫全 Allow、llms.txt 200、llms-full.txt 200、sitemap.xml 带 lastmod
- [x] augment_prompt 双调用点（ai-generator:40 批量 + :1066 单篇）、get_topic_seeds 调用点（autopilot-plan:111）
- [ ] 线上 wp_options `yimai_gso_geo_entity_settings` 原始 JSON（需 DB 访问，确认 sameAs 完整数组与 entity_address 分店字段）
- [ ] 首页 @graph HealthAndBeautyBusiness×2 块的产出归属（S-2）
- [ ] schema-generator build_person 是否读取 author 档案的 GEO 字段（S-8 关联）
- [ ] admin JS 中引用 ajax 的实际 action 名（B-7 关联）

---

## 11. 面向站长（用户）的使用建议

以下按「投入产出比」排序，针对一麦瑜伽（yimaiyoga.com）当前实际状态给出。

### 第一优先级：GEO 实体补全（10 分钟，直接提升 AI 引擎对品牌的认知）

**当前状态**：实体已配置（name/email/telephone/双店地址/sameAs 2 条小红书+抖音），增强 LocalBusiness JSON-LD 已全站生效——这部分做得不错。但按审计 E2 因子标准（sameAs ≥4 为优）还差 2 条：

1. **补 sameAs 到 ≥4 条**（每补 1 条，GEO 评分实体维度 +10~20 分）：
   - 知乎机构号（如有）：https://www.zhihu.com/org/一麦瑜伽
   - 微信视频号/公众号的介绍页 URL（视频号可通过公众号主页间接引用）
   - 大众点评/美团店铺页 URL（本地商家的重要实体信号，且这两页常出现在 AI 搜索「宁波瑜伽馆推荐」的引用源里）
   - 百度百科（若品牌词条存在；没有词条不必强求）
2. **entity_type 建议保持 LocalBusiness**（当前选择正确）。若插件 v2.9.0 增加 HealthAndBeautyBusiness 选项（C-2 建议），瑜伽馆行业的标准类型是 HealthAndBeautyBusiness，届时切换可让 address 字段更语义化。
3. **实体描述**（entity_description）当前是店铺介绍口径，建议改写为「一段话讲清：是谁 + 在哪 + 卖什么 + 凭什么可信」，因为这段文字会被 augment_prompt 注入 AI 写作、也会进 Knowledge Graph。

### 第二优先级：作者档案填写（5 分钟，E-E-A-T 信号）

**当前状态**：文章作者显示为 Person "shunan"（有作者存档页 URL），但 build_author_person 是否带 jobTitle/knowsAbout 需查 user_meta 实际值（f2 复核）。

1. 在「用户 → 个人资料」的 **GEO 作者专业信息** 区块填写：职务（如「一麦瑜伽创始人 · 普拉提教练」）、擅长领域（体态理疗、产后修复、器械普拉提）、从业年限、资质证书（RYT200/RYT500 认证、普拉提认证）；
2. sameAs 填教练个人知乎/小红书主页——作者实体与品牌实体在 Knowledge Graph 中形成关联网络；
3. 填写后 GEO 审计 E3 因子（作者档案覆盖率）立即达标，且作者存档页 Person Schema 变完整。

### 第三优先级：知识中心持续喂养（长期，AI 写作质量的根）

**当前状态**：三张知识表已建（2.2.0 迁移建表），llms.txt 的品牌描述与文章口径一致性良好，说明已有基础资料。建议：

1. **把「客服常被问的问题」导入知识中心**（visibility 设 public）：课程价格体系、预约方式、两家店的差异、停车信息——这些是 AI 搜索引擎回答「一麦瑜伽怎么样/多少钱」时最需要的结构化事实；
2. **敏感资料分级**：内部价格表/会员数据设 internal 时记住——internal 资料会进 AI 写作 prompt（S-10），有「被写进文章」的理论风险。真正敏感的（员工薪资、会员个人信息）设 restricted/blocked；
3. 每月增量导入新课程/新活动资料，get_topic_seeds 会自动围绕品牌知识派生自动发布选题。

### 第四优先级：写作习惯（每篇文章 2 分钟）

1. **首段 1-2 句直接给结论**（答案前置占 GEO 评分 25%）——AI 引擎抓取时按段落独立理解，首段有结论的文章被直接引用率高；
2. **每篇至少 1 处数据引用 + 1 处命名来源**（「根据 XX 研究/课程实测数据」），触发 Citation_Helper 加分（引用分 +30/+25）；
3. **保存文章后看一眼编辑页的双引擎评分面板**：GEO 分 <60 时面板 tips 会给具体改进方向；SEO 与 GEO 分差 >15 时点「协同建议」；
4. **Schema 类型选具体的**：FAQPage/HowTo/Product 比 Article 多拿 40 分 schema_coverage 分——课程介绍页选 Product、训练教程选 HowTo、答疑文选 FAQPage。

### 第五优先级：定期运维动作

1. **每月跑一次「AI 就绪度审计」**（GEO优化 → AI就绪度审计）——22 因子体检报告 + 优先改进清单，按清单从低分因子逐个修；
2. **每季度查看 GEO 评分批量扫描**的低分文章（<50 分），优先翻新流量页；
3. **robots.txt 不要手改**——AI 爬虫区块由插件爬虫编辑器统一管理（线上当前 13 个 AI 爬虫全 Allow，这是正确配置，保持）；
4. **llms.txt 每次内容大更新后确认可访问**（插件 LLMs 生成面板有一键验证）。

### 不需要动的（已验证健康）
- llms.txt/llms-full.txt 双文件在线 ✅
- robots.txt AI 爬虫全开放 ✅
- sitemap 带 lastmod ✅
- 首页/文章页 JSON-LD 输出链路完整 ✅
- 知识中心 → AI 写作注入链路正常 ✅

### 等 v2.9.0 修复后自动受益的
- B-1 修复后：首页/页面也会输出 ai-description（无需用户操作）
- B-5/B-6 修复后：文章 citation 数组从「兜底自引用」变成真实引用列表
- C-2 若采纳：HealthAndBeautyBusiness 类型选项
- 修复发布后记得在后台「GEO实体」重新保存一次实体配置（触发缓存刷新）
