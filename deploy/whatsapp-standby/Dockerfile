FROM php@sha256:cdb77e306d6bb99db40e513fbe036ecc6c53b575cbcb7e581387845fef26d755
WORKDIR /app
COPY os/mvp /app/os/mvp
COPY os/tests/mvp/check.php /app/os/tests/mvp/check.php
USER 65532:65532
CMD ["php", "/app/os/tests/mvp/check.php"]
