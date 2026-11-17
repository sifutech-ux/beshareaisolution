# Build from the verified, currently deployed account image. Never build over IPO.
# docker build -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=<verified-image> .
ARG ACCOUNT_BASE_IMAGE
FROM ${ACCOUNT_BASE_IMAGE}

# This release extends the b282d24 saved-outcomes account layer. A newer baseline
# must be reviewed and these expectations intentionally updated before release.
RUN echo '63c99873f3fef143d112bd1d9c7aa653f5327e54d4e2aae2d4da144e7d592334  /app/pelanggan_copilot.py' | sha256sum -c - \
 && echo '064377cd793c5ad8750a4cde743f31d50f72719631d039af533c7b6f83d736b7  /app/pelanggan_akaun.py' | sha256sum -c - \
 && echo '3ef8967bd36f535a6032b94ce0446c293780da2c19ac21d3b3a746a623055914  /app/akaun_pelayan.py' | sha256sum -c -

# Connections fail closed without authenticated encryption. Require the chosen
# base image to contain cryptography; retain its runtime user and dependencies.
RUN python -c 'from cryptography.fernet import Fernet; Fernet.generate_key()'
COPY copilot/account/pelanggan_copilot.py copilot/account/pelanggan_hasil.py \
     copilot/account/pelanggan_marketing.py copilot/account/marketing_social.py \
     copilot/account/marketing_worker.py /app/
