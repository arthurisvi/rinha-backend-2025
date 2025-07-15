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

# Instalar apenas extensões essenciais
RUN docker-php-ext-install pcntl sockets \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && pecl install swoole-5.0.3 \
    && docker-php-ext-enable swoole

# Configurações PHP ultra-otimizadas para health check
RUN { \
    echo "memory_limit=24M"; \
    echo "max_execution_time=30"; \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=6"; \
    echo "opcache.max_accelerated_files=1000"; \
    echo "opcache.revalidate_freq=300"; \
    echo "opcache.fast_shutdown=1"; \
    echo "zend.enable_gc=1"; \
    echo "gc_probability=1"; \
    echo "gc_divisor=100"; \
    echo "session.auto_start=0"; \
    echo "swoole.use_shortname=Off"; \
    echo "swoole.unixsock_buffer_size=2048"; \
    echo "swoole.task_worker_num=1"; \
    echo "swoole.worker_num=1"; \
    echo "swoole.task_enable_coroutine=1"; \
    echo "swoole.max_coroutine=30"; \
    echo "swoole.task_max_request=500"; \
} > /usr/local/etc/php/conf.d/worker-health.ini

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
RUN addgroup -g 1001 -S health && \
    adduser -u 1001 -S health -G health

WORKDIR /opt/www

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copiar apenas composer.json primeiro (para cache do Docker)
COPY cmd/workers/health-check/composer.json .
RUN chown health:health composer.json

# Instalar dependências do Swoole
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar código da aplicação
COPY cmd/workers/health-check/index.php .

# Ajustar permissões finais
RUN chown -R health:health /opt/www

USER health

CMD ["php", "index.php"]
