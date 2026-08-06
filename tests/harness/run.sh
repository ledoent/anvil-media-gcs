#!/usr/bin/env bash
# Two scenarios against a real WordPress:
#   A. clean install — uploads go to the bucket from the start
#   B. migration     — an existing local library is rsynced in, DuroPC-style,
#                      and must serve without any database rewrite
set -euo pipefail
WP="wp --allow-root --path=/var/www/html"
BUCKET="${ANVIL_BUCKET:-anvil-test}"
GCS="${STORAGE_EMULATOR_HOST:-http://gcs:4443}"
pass=0; fail=0
ok(){ printf "  \033[32mPASS\033[0m  %s\n" "$1"; pass=$((pass+1)); }
no(){ printf "  \033[31mFAIL\033[0m  %s\n" "$1"; fail=$((fail+1)); }
chk(){ if [ "$2" = "$3" ]; then ok "$1"; else no "$1 (want '$3', got '$2')"; fi; }

curl -sS -X POST "$GCS/storage/v1/b?project=test-project" \
  -H 'Content-Type: application/json' -d "{\"name\":\"$BUCKET\"}" >/dev/null || true

echo "=============================================================="
echo " SCENARIO A — clean install"
echo "=============================================================="
$WP core install --url=http://localhost:8099 --title=Anvil \
  --admin_user=admin --admin_password=admin --admin_email=a@b.test --skip-email >/dev/null
ok "WordPress installed"

chk "upload_dir basedir points at the bucket" \
  "$($WP eval 'echo wp_upload_dir()["basedir"];')" "gs://$BUCKET"

# A real upload through core, generating intermediate sizes.
# Generate locally: the harness must not depend on network egress.
php -r '$i=imagecreatetruecolor(1200,800);
  imagefilledrectangle($i,0,0,1200,800,imagecolorallocate($i,20,80,160));
  imagefilledellipse($i,600,400,500,300,imagecolorallocate($i,240,180,40));
  imagejpeg($i,"/tmp/src.jpg",90);'
[ -s /tmp/src.jpg ] || { echo "harness: could not create test image"; exit 1; }
ID=$($WP media import /tmp/src.jpg --porcelain)
[ -n "$ID" ] && ok "media import succeeded (attachment $ID)" || no "media import"

REL=$($WP eval "echo get_post_meta($ID,'_wp_attached_file',true);")
case "$REL" in
  20*/*/*.jpg) ok "YYYY/MM layout preserved in _wp_attached_file ($REL)" ;;
  *) no "layout not preserved: $REL" ;;
esac

# The object must actually exist in the bucket.
ENC=$(printf %s "$REL" | sed 's|/|%2F|g')
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$GCS/storage/v1/b/$BUCKET/o/$ENC")
chk "original object stored in bucket" "$CODE" "200"

# Intermediate sizes are the real test of the stream wrapper under Imagick.
SIZES=$($WP eval "\$m=wp_get_attachment_metadata($ID); echo count(\$m['sizes'] ?? []);")
[ "${SIZES:-0}" -ge 2 ] && ok "intermediate sizes generated ($SIZES)" || no "intermediate sizes ($SIZES)"

THUMB=$($WP eval "\$m=wp_get_attachment_metadata($ID); \$d=dirname(\$m['file']); \$s=reset(\$m['sizes']); echo \$d.'/'.\$s['file'];")
ENC2=$(printf %s "$THUMB" | sed 's|/|%2F|g')
CODE2=$(curl -s -o /dev/null -w '%{http_code}' "$GCS/storage/v1/b/$BUCKET/o/$ENC2")
chk "a generated size is in the bucket" "$CODE2" "200"

URL=$($WP eval "echo wp_get_attachment_url($ID);")
case "$URL" in
  http://localhost:4443/$BUCKET/*) ok "attachment URL served from bucket" ;;
  *) no "attachment URL: $URL" ;;
esac

SRCSET=$($WP eval "echo (string) wp_get_attachment_image_srcset($ID,'medium');")
[ -n "$SRCSET" ] && ok "srcset generated (not silently dropped)" || no "srcset EMPTY — layout mismatch"

# Filename collision handling goes through pre_wp_unique_filename_file_list.
ID2=$($WP media import /tmp/src.jpg --porcelain)
REL2=$($WP eval "echo get_post_meta($ID2,'_wp_attached_file',true);")
[ "$REL" != "$REL2" ] && ok "collision produced a unique name ($(basename "$REL2"))" || no "collision reused the same name"

echo
echo "=============================================================="
echo " SCENARIO B — migration of an existing library (DuroPC-style)"
echo "=============================================================="
# Simulate a pre-existing local library: objects placed directly in the bucket
# at their original relative paths, exactly as `gcloud storage rsync` would,
# with attachment rows written as if the site had always been local.
YEAR=2019; MONTH=03
for n in legacy-a legacy-b; do
  php -r "\$i=imagecreatetruecolor(800,600); imagefilledrectangle(\$i,0,0,800,600,imagecolorallocate(\$i,200,120,20)); imagejpeg(\$i,'/tmp/$n.jpg',85);"
  curl -sS -X POST --data-binary "@/tmp/$n.jpg" -H 'Content-Type: image/jpeg' \
    "$GCS/upload/storage/v1/b/$BUCKET/o?uploadType=media&name=$YEAR/$MONTH/$n.jpg" >/dev/null
done
ok "existing media rsynced into bucket (no DB touched yet)"

LID=$($WP eval "
  \$id = wp_insert_attachment(['post_title'=>'legacy-a','post_mime_type'=>'image/jpeg','post_status'=>'inherit']);
  update_post_meta(\$id,'_wp_attached_file','$YEAR/$MONTH/legacy-a.jpg');
  echo \$id;")
ok "legacy attachment row created (id $LID)"

LURL=$($WP eval "echo wp_get_attachment_url($LID);")
chk "legacy media serves from bucket, no DB rewrite" \
  "$LURL" "http://localhost:4443/$BUCKET/$YEAR/$MONTH/legacy-a.jpg"

LCODE=$(curl -s -o /dev/null -w '%{http_code}' "$LURL")
chk "legacy object actually fetchable" "$LCODE" "200"

BACK=$($WP eval "echo (int) attachment_url_to_postid('$LURL');")
chk "attachment_url_to_postid resolves a CDN-style URL" "$BACK" "$LID"

echo
echo "=============================================================="
printf " passed: %d   failed: %d\n" "$pass" "$fail"
echo "=============================================================="
[ "$fail" -eq 0 ]
