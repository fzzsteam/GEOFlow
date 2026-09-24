#!/usr/bin/env sh
set -eu

cd /var/www/html

SERVICE="${1:-}"

case "$SERVICE" in
  worker)
    exec php artisan queue:work redis \
      --queue=system-updates,geoflow,distribution,theme-replication,default \
      --sleep=1 \
      --tries=1 \
      --timeout=930 \
      --memory=128 \
      --max-jobs=100 \
      --max-time=3600
    ;;
  ai-quality-front)
    exec php artisan geoflow:work-ai-quality front
    ;;
  ai-quality-backfill)
    exec php artisan geoflow:work-ai-quality backfill
    ;;
  ai-optimization)
    exec php artisan geoflow:work-ai-optimization
    ;;
  knowledge)
    exec php artisan queue:work redis \
      --queue=knowledge \
      --sleep=1 \
      --tries=1 \
      --timeout=210 \
      --memory=128 \
      --max-jobs=20 \
      --max-time=1800
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  reverb)
    exec php artisan reverb:start --host="${REVERB_SERVER_HOST:-127.0.0.1}"
    ;;
  *)
    echo "[sae-service] error: unknown service: ${SERVICE}" >&2
    exit 64
    ;;
esac
