cd $(dirname $0)

php74 /usr/bin/composer install
chmod 755 data/
chmod 644 data/*
chmod 700 update.sh
chmod 700 vendor/ config/
chmod 600 config/token.json config/credentials.json
chmod 400 .gitignore gclient.php
chmod 600 composer.json composer.lock time_series_covid19_global.sh time_series_covid19_global.php
