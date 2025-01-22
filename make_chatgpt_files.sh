#!/bin/bash
mkdir -p chatgpt
for i in resources/js/Components/*.vue; do echo "****** $i ******"; echo ""; cat $i; echo ""; done > chatgpt/all-components.txt
git ls-tree -r master --name-only > chatgpt/list-of-files.txt
mysqldump -u reliefinventory -p --databases reliefinventory --compact --no-data > chatgpt/reliefinventory.sql
sed '/\/\*/,/\*\//d' chatgpt/reliefinventory.sql > chatgpt/reliefinventory.sql.txt
rm chatgpt/reliefinventory.sql