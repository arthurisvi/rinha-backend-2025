FROM php:8.1-cli-alpine

# Instalar dependências completas para compilação
RUN apk add --no-cache --virtual .build-deps \
    build-base \
    autoconf \
    libtool \
    openssl-dev \
    pcre-dev \
    zlib-dev \
    linux-headers \
    libstdc++ \
    && apk add --no-cache \
    libstdc++

# Instalar extensões PHP mínimas
RUN docker-php-ext-install pcntl sockets \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && pecl install swoole-5.0.3 \
    && docker-php-ext-enable swoole

# Configurações PHP para baixo consumo
RUN { \
    echo "memory_limit=64M"; \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=16"; \
    echo "opcache.max_accelerated_files=4000"; \
    echo "opcache.revalidate_freq=60"; \
    echo "opcache.fast_shutdown=1"; \
    echo "zend.enable_gc=1"; \
    echo "gc_probability=1"; \
    echo "gc_divisor=100"; \
    echo "session.auto_start=0"; \
    echo "swoole.use_shortname=Off"; \
    echo "swoole.unixsock_buffer_size=8192"; \
} > /usr/local/etc/php/conf.d/optimized.ini

# Limpeza agressiva
RUN apk del .build-deps && \
    rm -rf /var/cache/apk/* \
           /tmp/pear \
           /usr/local/lib/php/doc \
           /usr/local/lib/php/test \
           /usr/local/include/php \
           /usr/local/php/man \
           /usr/share/man

# Criar usuário não-root primeiro
RUN addgroup -g 1001 -S app && \
    adduser -u 1001 -S app -G app

WORKDIR /opt/www

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copiar apenas composer.json primeiro (para cache do Docker)
COPY cmd/api/composer.json .
RUN chown app:app composer.json

# Instalar dependências (gera vendor/ e composer.lock no container)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar todo o código da aplicação
COPY cmd/api/app/ ./app/

# Ajustar permissões finais
RUN chown -R app:app /opt/www

USER app

EXPOSE 8080

CMD ["php", "app/index.php"]