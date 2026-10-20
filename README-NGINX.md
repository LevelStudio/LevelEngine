# Nginx — reguły dla LevelEngine 1.0.0 FINAL

Wstaw poniższe bloki do istniejącego `server { ... }`, po dopasowaniu `root` i socketu PHP. Przykład zakłada CMS w katalogu głównym domeny. Konfigurację hostingu sprawdź poleceniem `nginx -t` przed przeładowaniem.

```nginx
root /var/www/levelengine;
index index.php;
client_max_body_size 32m;

location ~ (^|/)\. { deny all; }
location ~* ^/(config\.php|composer\.(json|lock))$ { deny all; }
location ~ ^/(lib|storage|backups|bin|templates|plugins)(/|$) { deny all; }
location ~ ^/upgrade/(backups|runtime)(/|$) { deny all; }
location = /install/schema.sql { deny all; }
location ~* \.(sql|log|bak|tmp|key|ini|pem)(\.|$) { deny all; }

# These locations are static-only; no uploaded script reaches PHP-FPM.
location ^~ /uploads/ {
    location ~* \.(php[0-9]?|phtml|phar)(\.|$) { deny all; }
    try_files $uri =404;
    add_header X-Content-Type-Options nosniff always;
}
location ^~ /themes/ {
    location ~* \.(php[0-9]?|phtml|phar)(\.|$) { deny all; }
    try_files $uri =404;
}
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

Własne pluginy uruchamiane są przez rdzeń (`/plugin/...`, `plugin-api.php`, `admin/plugin.php`). Ich katalog nie wymaga publicznego dostępu. Jeśli plugin potrzebuje publicznych zasobów statycznych, udostępnij wyłącznie jego katalog zasobów według dokumentacji autora i pozostaw blokadę PHP.

Zweryfikuj, że pobranie `/config.php`, `/storage/vault.key`, `/backups/test.sql` i bezpośredniego pliku PHP w `/uploads/` kończy się 403/404. Tych reguł Nginx nie uruchamiano na lokalnym środowisku Windows — są przykładem do sprawdzenia przez administratora hostingu.
