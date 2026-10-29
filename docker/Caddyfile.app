# What every Relief Inventory site block serves, shared by Caddyfile (plain HTTP) and Caddyfile.https.
root * /var/www/html/public
encode zstd gzip
request_body {
	max_size 64MB
}
# The emoji font is ~10 MB and never changes between releases of an image: let browsers keep it.
@emojifont path /fonts/NotoColorEmoji.ttf
header @emojifont Cache-Control "public, max-age=604800"
php_server
