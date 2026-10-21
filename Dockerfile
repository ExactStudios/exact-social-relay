# Exact Social connect relay: PHP with Apache, serving only public/.
# The official image already has the curl and sodium extensions.
FROM php:8.3-apache

# Production PHP settings (no error display), and no version in headers.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
	&& echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/relay.ini"

# Web root is public/; every path goes to index.php. zz- loads after the
# image's security.conf, which would set ServerTokens back.
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
	&& sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
	&& printf '<Directory /app/public>\n\tFallbackResource /index.php\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-relay.conf

WORKDIR /app
COPY public/ public/
COPY src/ src/

# Records live at most 10 minutes, so the container's own disk is enough
# for one container; in-flight connects are lost on a redeploy.
RUN mkdir store && chown www-data:www-data store && chmod 700 store

EXPOSE 80
