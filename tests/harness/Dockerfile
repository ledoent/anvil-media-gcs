FROM wordpress:6.7-php8.3-apache

# wp-cli drives the scenarios. GD ships in the base image and is what generates
# intermediate sizes here — that exercises the same stream-wrapper write path
# Imagick would, without the pecl build. (Imagick against gs:// is covered
# separately: WP 5.6+ routes it through readImageBlob/getImageBlob precisely
# because streams have no FILE*.)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends less mariadb-client ca-certificates; \
    curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
    chmod +x /usr/local/bin/wp; \
    rm -rf /var/lib/apt/lists/*

COPY tests/harness/anvil-config.php /usr/local/share/anvil-config.php
