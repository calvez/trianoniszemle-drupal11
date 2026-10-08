#!/usr/bin/env bash
# Post-deploy smoke test: checks the key pages answer correctly. Needs only curl.
#   bash scripts/smoke-test.sh https://trianoniszemle.hu
# Exit code 1 if anything fails. Optional: SAMPLE_PDF=/system/files/... to also test a PDF download.
set -uo pipefail
BASE="${1:-https://trianoniszemle.hu}"; BASE="${BASE%/}"
fail=0
check() {   # path, expected text (grep -E), label
  local code body
  body=$(curl -sL -m 60 -o /tmp/smoke.$$ -w '%{http_code}' "$BASE$1") ; code=$body
  if [ "$code" = "200" ] && { [ -z "${2:-}" ] || grep -qE "$2" /tmp/smoke.$$; }; then printf '  [ OK ] %-34s %s\n' "$1" "${3:-}"
  else printf '  [FAIL] %-34s http %s (expected 200%s)\n' "$1" "$code" "${2:+ and text /$2/}"; fail=1; fi
}
echo "Smoke test: $BASE"
check /                       'Legfrissebb lapszámok' "homepage with latest issues"
check /evfolyamok             'Évfolyamok'            "issue index"
check /repertorium            'Repertórium'           "article register"
check "/repertorium?szerzo=Szidiropulosz" 'Szidiropulosz' "register filter"
check /szerzok                'Szerzők'               "author index"
check "/szerzok?betu=A"       'author-card'           "author cards"
check /blog                   'Hírek'                 "blog"
check "/search/node?keys=Trianon" 'search-result'     "search finds content"
check /rolunk                 ''                      "static page"
check /sitemap.xml            '<sitemapindex|<urlset' "sitemap"
check /robots.txt             'Sitemap:'              "robots.txt"
check /user/login             'name="pass"'           "login form"
if [ -n "${SAMPLE_PDF:-}" ]; then
  type=$(curl -sL -m 60 -o /dev/null -w '%{content_type}' "$BASE$SAMPLE_PDF")
  if [[ "$type" == application/pdf* ]]; then printf '  [ OK ] %-34s %s\n' "$SAMPLE_PDF" "PDF download"; else printf '  [FAIL] %-34s content-type %s\n' "$SAMPLE_PDF" "$type"; fail=1; fi
fi
# unpublished / hidden things must stay hidden
code=$(curl -s -o /dev/null -m 30 -w '%{http_code}' "$BASE/admin/content"); [ "$code" = "403" ] || [ "$code" = "302" ] || [ "$code" = "301" ] && printf '  [ OK ] %-34s %s\n' "/admin/content" "not public ($code)" || { printf '  [FAIL] /admin/content http %s (should not be public)\n' "$code"; fail=1; }
rm -f /tmp/smoke.$$
[ $fail -eq 0 ] && echo "All checks passed." || echo "SOME CHECKS FAILED."
exit $fail
