# Build on the verified billing account image; keep its secrets, guard and runtime user.
ARG BASE_IMAGE=copilot-account:billing-7118f90
FROM ${BASE_IMAGE}
RUN echo '5a431117947641e464ab82a5e72b296d30f6997b470c355f98c1b6a1c2dd1e6a  /app/pelanggan_copilot.py' | sha256sum -c - \
 && echo '064377cd793c5ad8750a4cde743f31d50f72719631d039af533c7b6f83d736b7  /app/pelanggan_akaun.py' | sha256sum -c - \
 && echo '3ef8967bd36f535a6032b94ce0446c293780da2c19ac21d3b3a746a623055914  /app/akaun_pelayan.py' | sha256sum -c -
COPY pelanggan_copilot.py pelanggan_hasil.py /app/
