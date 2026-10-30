#!/usr/bin/env bash
set -Eeuo pipefail

# 一麦GSO双擎优化插件 · 双平台发布脚本（对齐一麦官网主题 tools/release.sh 模式）
# 用法：./release.sh   （在插件代码提交并 push 之后运行）
# 行为：打包插件 → 发布/刷新 GitHub 与 Gitee 的 auto-latest Release（含 zip 与 manifest）
# 更新包下载主渠道是 GitHub codeload 分支归档（实时生成），Gitee zip 资产仅作兜底与人工下载

# 本脚本位于插件仓库内（tools/release.sh），仓库根即插件根（该插件的 git 仓库
# 没有外层目录），因此插件目录就是 tools/ 的上一级。
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${TMPDIR:-/tmp}/yimai-gso-release"
GITHUB_REPO="a6828464/yimai-gso-optimizer"
GITEE_REPO="meng-taoo/yimai-gso-optimizer"
TAG="auto-latest"

ZIP_NAME="yimai-gso-optimizer-latest.zip"
MANIFEST_NAME="yimai-gso-optimizer-manifest.json"
MAIN_FILE="yimai-gso-optimizer.php"

GITEE_API="https://gitee.com/api/v5"

command -v gh >/dev/null || { echo "错误：未安装 gh CLI"; exit 1; }

# 版本号以插件主文件头为准（与 YIMAI_GSO_VERSION 同步改）
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_DIR/$MAIN_FILE" | head -1 | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "错误：未能从 $MAIN_FILE 解析版本号"; exit 1; }

# ── 推送校验：主仓库 Gitee 必须与本地一致 ──
LOCAL_SHA="$(git -C "$PLUGIN_DIR" rev-parse HEAD)"
GE_SHA="$(git -C "$PLUGIN_DIR" ls-remote https://gitee.com/${GITEE_REPO}.git main | cut -f1)"
if [ "$GE_SHA" != "$LOCAL_SHA" ]; then
  echo "错误：Gitee main (${GE_SHA}) 与本地 (${LOCAL_SHA}) 不一致！请先 git push 并校验。"
  exit 1
fi
echo "── 主仓库校验通过：Gitee main = ${LOCAL_SHA:0:7}"

# ── GitHub 为发布镜像（codeload 更新包的来源）：落后时自动补推，最多重试 3 次 ──
GH_SHA="$(gh api repos/$GITHUB_REPO/commits/main --jq '.sha' 2>/dev/null || true)"
if [ "$GH_SHA" != "$LOCAL_SHA" ]; then
  echo "── GitHub 落后（${GH_SHA:-未知}），自动补推…"
  ok=0
  for i in 1 2 3; do
    if git -C "$PLUGIN_DIR" push github main 2>&1 | tail -1; then
      GH_SHA="$(gh api repos/$GITHUB_REPO/commits/main --jq '.sha' 2>/dev/null || true)"
      [ "$GH_SHA" = "$LOCAL_SHA" ] && { ok=1; echo "── GitHub 已补推至 ${LOCAL_SHA:0:7}"; break; }
    fi
    echo "  第 $i 次补推未确认，重试…"; sleep 5
  done
  if [ "$ok" -ne 1 ]; then
    echo "错误：GitHub 无法同步到 $LOCAL_SHA，codeload 更新包将是旧代码，拒绝发布。"
    echo "请排查网络后重试，或手动：git push github main"
    exit 1
  fi
else
  echo "── GitHub 镜像已是最新"
fi

# 发布信息：日期取最新提交，更新说明取 CHANGELOG.md 顶部小节
UPDATED="$(git -C "$PLUGIN_DIR" log -1 --format=%cs)"
NOTES="$(python3 - "$PLUGIN_DIR/CHANGELOG.md" <<'PYEOF'
import sys
lines = [l.rstrip() for l in open(sys.argv[1], encoding='utf-8')]
notes, in_top = [], False
for line in lines:
    if line.startswith('## '):
        if in_top:
            break
        in_top = True
        continue
    if in_top and line.startswith('- '):
        notes.append(line[2:])
print('\n'.join('- ' + n for n in notes))
PYEOF
)"
[ -n "$NOTES" ] || { echo "错误：CHANGELOG.md 顶部未找到更新说明"; exit 1; }

echo "── 发布 v$VERSION ($UPDATED)"

rm -rf "$DIST_DIR" && mkdir -p "$DIST_DIR"

echo "── 打包插件（git archive：遵循 .gitignore，包根即插件根）"
git -C "$PLUGIN_DIR" archive --format=zip --output "$DIST_DIR/$ZIP_NAME" HEAD
ZIP_SHA="$(shasum -a 256 "$DIST_DIR/$ZIP_NAME" | cut -d' ' -f1)"
python3 - "$DIST_DIR/$MANIFEST_NAME" "$VERSION" "$UPDATED" "$NOTES" "$ZIP_SHA" <<'PYEOF'
import json, sys
notes = [l[2:] for l in sys.argv[4].split('\n') if l.startswith('- ')]
json.dump({'version': sys.argv[2], 'updated': sys.argv[3], 'notes': notes, 'sha256': sys.argv[5]},
          open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
PYEOF
ls -la "$DIST_DIR"

# ---------- GitHub ----------
echo "── GitHub：刷新 $TAG Release"
gh release delete "$TAG" --repo "$GITHUB_REPO" --yes --cleanup-tag 2>/dev/null || true
gh release create "$TAG" --repo "$GITHUB_REPO" --target main \
  --title "一麦GSO双擎优化 v$VERSION" \
  --notes "自动发布：main 分支 ($UPDATED)。后台「在线更新」使用该发行包。

$NOTES" \
  "$DIST_DIR/$ZIP_NAME" "$DIST_DIR/$MANIFEST_NAME"

# ---------- Gitee ----------
echo "── Gitee：刷新 $TAG Release"
GTOKEN="$(printf 'protocol=https\nhost=gitee.com\n' | git credential fill 2>/dev/null | sed -n 's/^password=//p')"
[ -n "$GTOKEN" ] || { echo "错误：未取到 Gitee 凭据（钥匙串）"; exit 1; }

# 已存在同名 tag 的 release 则先删除（对齐主题发布脚本的做法）
existing="$(curl -sfS -G "$GITEE_API/repos/$GITEE_REPO/releases" \
  --data-urlencode "access_token=$GTOKEN" --data-urlencode "per_page=100")" || existing="[]"
rid="$(printf '%s' "$existing" | python3 -c "
import json,sys
tag=sys.argv[1]
print(next((str(x['id']) for x in json.load(sys.stdin) if x.get('tag_name')==tag),''))
" "$TAG")"
if [ -n "$rid" ]; then
  echo "  删除旧 release id=$rid"
  curl -sS -X DELETE "$GITEE_API/repos/$GITEE_REPO/releases/$rid" --data-urlencode "access_token=$GTOKEN" >/dev/null
fi

# Gitee 侧必须同时重建 tag：只删 release 不删 tag 会让 tag 永久停在旧提交。
# 2026-10-31 实测：auto-latest 停在 1b1bf85（v2.4.0），Gitee 平台「源码包」
# (auto-latest.zip / auto-latest.tar.gz) 与 tag 树长期是旧代码，而 Release 资产是新的。
# Gitee API v5 没有删除 tag 的端点（DELETE /tags/{name} 实测 404，只有 GET），
# 因此改用 git 协议删除远端 tag（git push :refs/tags/<tag>，实测有效）；
# 删掉后下面创建 release 时 Gitee 会把 tag 指向 target_commitish。
echo "  删除旧 tag ${TAG}（git 协议，Gitee API 无此端点）"
if git -C "$PLUGIN_DIR" ls-remote --tags "https://gitee.com/${GITEE_REPO}.git" "refs/tags/${TAG}" 2>/dev/null | grep -q "${TAG}"; then
  git -C "$PLUGIN_DIR" push "https://gitee.com/${GITEE_REPO}.git" ":refs/tags/${TAG}" 2>&1 | tail -1 || true
fi

body="$(printf '自动发布：main 分支 (%s)。后台「在线更新」使用该发行包。\n\n%s' "$UPDATED" "$NOTES")"
response="$(curl -sfS -X POST "$GITEE_API/repos/$GITEE_REPO/releases" \
  --data-urlencode "access_token=$GTOKEN" \
  --data-urlencode "tag_name=$TAG" \
  --data-urlencode "target_commitish=main" \
  --data-urlencode "name=一麦GSO双擎优化 v$VERSION" \
  --data-urlencode "body=$body")"
rid="$(printf '%s' "$response" | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')"

echo "  上传附件（release id=${rid}，最多重试 5 次）"
for f in "$ZIP_NAME" "$MANIFEST_NAME"; do
  ok=0
  for attempt in 1 2 3 4 5; do
    if err="$(curl --http1.1 --fail-with-body -sS --connect-timeout 20 --max-time 600 \
      -X POST "$GITEE_API/repos/$GITEE_REPO/releases/${rid}/attach_files" \
      -F "access_token=$GTOKEN" \
      -F "file=@$DIST_DIR/$f" 2>&1)"; then
      echo "  ✓ $f"
      ok=1
      break
    fi
    echo "  ✗ $f 第 $attempt 次失败：$err"
    [ "$attempt" -lt 5 ] && sleep $((attempt * 3))
  done
  [ "$ok" -eq 1 ] || { echo "错误：$f 连续 5 次上传失败，中止"; exit 1; }
done

echo "── 校验双平台资产可匿名访问"
for url in \
  "https://github.com/$GITHUB_REPO/releases/download/$TAG/$MANIFEST_NAME" \
  "https://gitee.com/$GITEE_REPO/releases/download/$TAG/$MANIFEST_NAME"; do
  code="$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 20 "$url")"
  echo "  [$code] $url"
done

echo "发布完成：v$VERSION"
