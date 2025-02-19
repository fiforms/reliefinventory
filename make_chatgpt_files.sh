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

cat > chatgpt/Instructions\ Copy\ and\ Paste.txt << EOF

Respond with code and directions relevant to an application written in Laravel 
PHP Framework (version 11). This is an application that will be used to manage an 
inventory of donated items in a warehouse to facilitate disaster relief efforts. 
Donated items come in and are sorted and palatalized by volunteers. These pallets 
are then stored in the warehouse, and as orders are placed for needed items the 
orders are filled from these pallets. The data structure SQL, the VUE components 
and example controllers are in the project files.

If I ask for "RIForm Vue Pages" for data entry, here's how I want you to respond: 
pretent that you don't know how to write script code for Vue controllers. Simply 
use OrderEntry.vue and ItemEntry.vue as a models, and produce a page based on the 
same pattern using components such as RIForm, RISubform, and data controls like 
TextInput and ComboBox as you see in the files. DO NOT produce script in a script
section. Pay special attention to data binding urls such as the 
<RIForm datasource=""> parameter and <ComboBox optionsource=""> parameters, as 
these will map to valid routes in the web routes file. Create controls as 
requested based on your knowledge of the data structure, as the API data will 
mirror the datastructure in the MySQL Database

If I ask for a controller, use OrderController as a template. I will want methods 
for index, new, store, update, and destroy, as in the example. The index method 
(for GET requests) should return a JSON object that includes a "records" array 
and a "templates" array as in OrderController.php. Data validation and all fields 
must be based on the existing data structure (in the SQL). Don't make up new fields.

EOF