FROM php:8.4-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05
RUN docker-php-ext-install -j2 pdo_mysql pcntl
COPY --from=composer:2@sha256:d0c32820c62cc860fddd22adceed4eeda7698b093a7a88d503414172ce400626 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
USER 1000:1000
