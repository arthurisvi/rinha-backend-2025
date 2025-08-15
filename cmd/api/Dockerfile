FROM php:8.4-cli-alpine AS builder

# Instalar dependências completas para compilação
RUN apk add --no-cache --virtual .build-deps \
    build-base \
    autoconf \
    brotli-dev \
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
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install swoole \
    && docker-php-ext-enable swoole

# Configurações PHP para baixo consumo
RUN { \
    echo "memory_limit=64M"; \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=16"; \
    echo "opcache.max_accelerated_files=4000"; \
    echo "opcache.revalidate_freq=60"; \
    echo "opcache.fast_shutdown=1"; \
    echo "opcache.enable_cli=1"; \
    echo "opcache.interned_strings_buffer=8"; \
    echo "opcache.jit_buffer_size=32M"; \
    echo "opcache.jit=tracing"; \
    echo "zend.enable_gc=0"; \
    echo "gc_probability=1"; \
    echo "gc_divisor=100"; \
    echo "session.auto_start=0"; \
} > /usr/local/etc/php/conf.d/optimized.ini

# Limpeza
RUN apk del .build-deps && rm -rf /var/cache/apk/* /tmp/pear

# Criar usuário não-root primeiro
RUN addgroup -g 1001 -S app && adduser -u 1001 -S app -G app
USER app

WORKDIR /opt/www

COPY --chown=app:app cmd/api/server.php .

EXPOSE 8080
CMD ["php", "server.php"]
