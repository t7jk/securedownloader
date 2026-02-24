#!/bin/bash

SRC="/home/t7jk/Code/PIT-downloader/"
DST="/var/www/html/wordpress/wp-content/plugins/obsluga-dokumentow-ksiegowych/"

sudo mkdir -p "$DST"
sudo chown apache:apache "$DST"

echo "Startowanie synchronizacji: $SRC -> $DST"

while true; do
    sudo rsync -a --delete \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='.claude' \
        --exclude='*.zip' \
        --exclude='*.csv' \
        "$SRC" "$DST"
    
    sudo chown -R apache:apache "$DST"
    sleep 2
done
