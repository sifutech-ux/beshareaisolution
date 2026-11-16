# Lab tooling only. No application source, credentials or host files in this image.
ARG PHP_IMAGE
FROM ${PHP_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends python3 curl git libonig-dev nodejs \
 && docker-php-ext-install pdo_mysql mbstring \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /lab
CMD ["sleep", "infinity"]
