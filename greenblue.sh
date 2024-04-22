#!/bin/bash

set -Eeuo pipefail

CURRENT_COLOR="blue"
TARGET_COLOR="green"
TARGET_PORT=19080
CURR_COLOR_CODE="\e[36m"
TGT_COLOR_CODE="\e[32m"
END_CODE="\e[0m"

# Assumes docker image is of the format <name>:prod-<color>
for i in $(docker ps | tail -n +2 | tr -s " " | cut -d " " -f 2 | cut -d "-" -f 3); do
  CURRENT_COLOR=$i
  if [[ "$CURRENT_COLOR" =~ ^green$ ]]; then
    CURRENT_COLOR="green"
    TARGET_COLOR="blue"
    TARGET_PORT=29080
    CURR_COLOR_CODE="\e[32m"
    TGT_COLOR_CODE="\e[36m"
  fi
done

echo -e "CURRENT COLOR: $CURR_COLOR_CODE$CURRENT_COLOR$END_CODE, TARGET: $TGT_COLOR_CODE$TARGET_COLOR$END_CODE:$TARGET_PORT\n"

docker-compose -f "docker-compose.php8-app.$TARGET_COLOR" up -d

for TRIES in 1 2 3; do
  echo -e "Test for new instance: $TGT_COLOR_CODE$TARGET_COLOR$END_CODE @ localhost:$TARGET_PORT\n"
  sleep 10
  if curl --head --silent --fail "localhost:$TARGET_PORT" 2> /dev/null; then
      break
  fi
done

sleep 10
echo -e "Container for $TGT_COLOR_CODE$TARGET_COLOR$END_CODE up and running, retargeting nginx ...\n"

rm "/etc/nginx/sites-enabled/default"
ln -s "/etc/nginx/sites-available/$TARGET_COLOR" "/etc/nginx/sites-enabled/default"
echo -e "Restarting nginx ...\n"
service nginx restart

echo -e "Tearing down old instance ($CURR_COLOR_CODE$CURRENT_COLOR$END_CODE)\n"
docker stop "ork3-php8-app-$CURRENT_COLOR"
docker rm "ork3-php8-app-$CURRENT_COLOR"

echo -e "$TGT_COLOR_CODE$TARGET_COLOR$END_CODE deployed"

sleep 10
docker system prune --volumes -f
docker ps