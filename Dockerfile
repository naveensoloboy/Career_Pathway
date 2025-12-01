# Dockerfile
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV LANG=C.UTF-8
ENV PORT=10000

# install packages: mariadb-server, php and extensions, supervisor, git, unzip, etc
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    mariadb-server \
    php8.1-cli php8.1-mysql php8.1-xml php8.1-mbstring php8.1-curl \
    curl ca-certificates supervisor net-tools procps \
    unzip \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# create app user (non-root)
RUN useradd -m -d /home/app -s /bin/bash app || true

# Create directories
RUN mkdir -p /var/www/html /docker-entrypoint /var/lib/mysql /var/run/mysqld /var/log/supervisor
RUN chown -R app:app /var/www/html /home/app

# copy app (assumes your app is in the repo root)
COPY . /var/www/html
RUN chown -R app:app /var/www/html

# supervisor config and entrypoint
COPY docker-entrypoint.sh /docker-entrypoint/docker-entrypoint.sh
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod +x /docker-entrypoint/docker-entrypoint.sh

# expose port for Render (Render gives container $PORT env; Dockerfile EXPOSE is informative)
EXPOSE 10000

# Use non-root user for serving PHP, but MariaDB will run as mysql user (installed package)
USER root

ENTRYPOINT ["/docker-entrypoint/docker-entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
