#!/usr/bin/env bash
# combine_project_all.sh — Microfin төслийг нэг markdown файлд нэгтгэнэ
# Ашиглах:  cd ~/Desktop/microfin && bash combine_project_all.sh
# Сонголт:  INCLUDE_HEAVY=1 (vendor/node_modules-г багтаах)

set -euo pipefail

ROOT_DIR="$(pwd)"
TS="$(date +"%Y%m%d_%H%M")"
OUT="microfin_bundle_${TS}.md"

# — Том/бинарь сангуудыг анхдагчаар хасна
EXCLUDES=(
  "./.git/*"
  "./.idea/*" "./.vscode/*"
  "./var/*"
  "./public/build/*" "./public/bundles/*"
  "./public/uploads/*"
  "./.DS_Store"
  "./**/*.png" "./**/*.jpg" "./**/*.jpeg" "./**/*.gif" "./**/*.svg" "./**/*.ico" "./**/*.webp"
  "./**/*.pdf" "./**/*.zip" "./**/*.tar" "./**/*.gz" "./**/*.tgz"
  "./**/*.sqlite" "./**/*.sqlite3" "./**/*.db"
  "./**/*.log" "./**/*.min.*" "./**/*.map"
  "./coverage/*" "./dist/*" "./build/*"
)

# — Хүнд сангуудыг оруулах бол: INCLUDE_HEAVY=1 ./combine_project_all.sh
if [[ "${INCLUDE_HEAVY:-0}" != "1" ]]; then
  EXCLUDES+=( "./vendor/*" "./node_modules/*" "./composer.lock" "./package-lock.json" "./yarn.lock" "./pnpm-lock.yaml" )
fi

# — Файлын өргөтгөлөөс code fence хэл тодорхойлох
lang_from_ext() {
  case "${1##*.}" in
    php) echo "php" ;;
    twig) echo "twig" ;;
    yaml|yml) echo "yaml" ;;
    json) echo "json" ;;
    js) echo "javascript" ;;
    ts) echo "typescript" ;;
    css) echo "css" ;;
    html|htm) echo "html" ;;
    md) echo "" ;;
    sh|bash) echo "bash" ;;
    sql) echo "sql" ;;
    env|local|dist) echo "" ;;
    *) echo "" ;;
  esac
}

# — find илэрхийллээ бэлдье
EXPR=(-type f)
for pat in "${EXCLUDES[@]}"; do
  EXPR+=( -not -path "$pat" )
done

# — Толгой хэсэг
{
  echo "# Microfin project bundle (all, including secrets)"
  echo ""
  echo "- Bundled at: \`$(date -Iseconds)\`"
  echo "- Root: \`$ROOT_DIR\`"
  echo "- Excluded patterns: \`${EXCLUDES[*]}\`"
  echo ""
  echo "> Анхаар: .env зэрэг нууц файлууд БАГТСАН байж болно. Хуваалцахын өмнө шалгаж, маск хийнэ үү."
  echo ""
} > "$OUT"

# — Файлуудаа нэгтгэнэ
mapfile -t FILES < <(find . "${EXPR[@]}" | sort)

COUNT=0
for f in "${FILES[@]}"; do
  lang="$(lang_from_ext "$f")"
  rel="${f#./}"
  {
    echo ""
    echo "<!-- ======================= $rel ======================= -->"
    echo "## \`$rel\`"
    echo ""
    if [[ "$lang" != "" ]]; then
      echo '```'"$lang"
      cat "$f"
      echo '```'
    else
      echo '```'
      cat "$f"
      echo '```'
    fi
  } >> "$OUT"
  COUNT=$((COUNT+1))
done

echo "✅ Нэгтгэл амжилттай: $OUT ($COUNT файл)"
