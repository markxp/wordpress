#!/bin/sh
# This script moves the generated nginx.conf from conf.d to /etc/nginx/
# It should run after 20-envsubst-on-templates.sh (which is why it's named 21-...)

if [ -f /etc/nginx/conf.d/nginx.conf ]; then
    echo "Relocating generated nginx.conf to /etc/nginx/nginx.conf"
    mv /etc/nginx/conf.d/nginx.conf /etc/nginx/nginx.conf
fi
