# Extend the verified existing account image. Keep its DB guard, intelligence,
# quotas and other modules; do not copy older snapshots over them.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
RUN echo 'bd4ec8244d76bc01e99a12d9fbca9888a81780a95140bfbfee387df47e19bf05  /app/akaun_pelayan.py' | sha256sum -c - \
 && echo '5e076c3fff6fa3e5bba027ea169f15ccce5d6ab31d10240175fed29ac3817e35  /app/pelanggan_akaun.py' | sha256sum -c -
COPY akaun_pelayan.py pelanggan_akaun.py pelanggan_bil.py /app/
# FROM preserves the existing unprivileged runtime user and entrypoint.
