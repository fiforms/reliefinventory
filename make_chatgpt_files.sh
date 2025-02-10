#!/bin/bash
mkdir -p chatgpt
for i in resources/js/Components/*.vue; do echo "****** $i ******"; echo ""; cat $i; echo ""; done > chatgpt/all-components.txt
git ls-tree -r master --name-only > chatgpt/list-of-files.txt
mysqldump -u reliefinventory -p --databases reliefinventory --compact --no-data > chatgpt/reliefinventory.sql.txt
sed -i -E ':a; s|/\*[^*]*\*/||g; ta'  chatgpt/reliefinventory.sql.txt   # Remove comments
sed -i 's/^\s*;\s*$//' chatgpt/reliefinventory.sql.txt                  # Remove extra semicolons
cp app/Http/Controllers/OrderController.php chatgpt/OrderController.php.txt
cp app/Http/Controllers/ItemController.php chatgpt/ItemController.php.txt
cp routes/web.php chatgpt/routes_web.php.txt
cp resources/js/Pages/OrderEntry.vue chatgpt/OrderEntry.vue.txt
