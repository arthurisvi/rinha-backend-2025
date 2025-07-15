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

# Instalar extensões PHP para worker
RUN docker-php-ext-install pcntl sockets \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && pecl install swoole-5.0.3 \
    && docker-php-ext-enable swoole

# Configurações PHP otimizadas para payment worker com corrotinas
RUN { \
    echo "memory_limit=56M"; \
    echo "max_execution_time=300"; \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=12"; \
    echo "opcache.max_accelerated_files=2000"; \
    echo "opcache.revalidate_freq=120"; \
    echo "opcache.fast_shutdown=1"; \
    echo "zend.enable_gc=1"; \
    echo "gc_probability=1"; \
    echo "gc_divisor=50"; \
    echo "session.auto_start=0"; \
    echo "swoole.use_shortname=Off"; \
    echo "swoole.unixsock_buffer_size=4096"; \
    echo "swoole.task_worker_num=2"; \
    echo "swoole.worker_num=1"; \
    echo "swoole.task_enable_coroutine=1"; \
    echo "swoole.max_coroutine=50"; \
    echo "swoole.task_max_request=1000"; \
} > /usr/local/etc/php/conf.d/worker-payment.ini

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
RUN addgroup -g 1001 -S worker && \
    adduser -u 1001 -S worker -G worker

WORKDIR /opt/www

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copiar apenas composer.json primeiro (para cache do Docker)
COPY cmd/workers/payment/composer.json .
RUN chown worker:worker composer.json

# Instalar dependências do Swoole
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copiar código da aplicação
COPY cmd/workers/payment/index.php .

# Ajustar permissões finais
RUN chown -R worker:worker /opt/www

USER worker

CMD ["php", "index.php"]