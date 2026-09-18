FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php8.1 php8.1-cli php8.1-mysql \
        php8.1-curl php8.1-mbstring php8.1-gd php8.1-xml \
        php8.1-zip php8.1-bcmath php8.1-sqlite3 php-ssh2 \
        curl ca-certificates freeradius-utils \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY apache.conf /etc/apache2/conf-available/firespot-local.conf
COPY php.ini /etc/php/8.1/mods-available/firespot-local.ini
COPY entrypoint.sh uploads.htaccess /opt/firespot-dev/
RUN a2enconf firespot-local \
    && phpenmod firespot-local \
    && chmod 755 /opt/firespot-dev/entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["/opt/firespot-dev/entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
