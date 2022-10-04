FROM php:8.1-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN apk update
RUN apk add bash
RUN apk add curl

# Install PHP extensions
RUN install-php-extensions ldap


# INSTALL COMPOSER
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

WORKDIR /app
COPY . .

#RUN composer install

CMD ["php", "bin/console", "ldap:sync", "-d", "-vvv"]
