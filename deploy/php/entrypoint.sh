#!/bin/sh
# storage — том; на свежем томе подкаталогов нет, а Laravel падает на них
# невнятной ошибкой прав при первом кэше вида.
set -e
for d in app/media app/private app/public framework/cache/data framework/sessions framework/views logs; do
    mkdir -p "/app/storage/$d"
done
exec "$@"
