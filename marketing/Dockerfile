# Build from the verified, currently deployed account image. Never build over IPO.
# docker build -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=<verified-image> .
ARG ACCOUNT_BASE_IMAGE
FROM ${ACCOUNT_BASE_IMAGE}

# This release extends the 4f86a43 Marketing account layer. A newer baseline
# must be reviewed and these expectations intentionally updated before release.
RUN echo 'bf4ee5fe170955833ec5c552a5d607a6135e635f086dd55f03c5412a66dc1b7c  /app/pelanggan_copilot.py' | sha256sum -c - \
 && echo '064377cd793c5ad8750a4cde743f31d50f72719631d039af533c7b6f83d736b7  /app/pelanggan_akaun.py' | sha256sum -c - \
 && echo '3ef8967bd36f535a6032b94ce0446c293780da2c19ac21d3b3a746a623055914  /app/akaun_pelayan.py' | sha256sum -c -

# Connections fail closed without authenticated encryption. Require the chosen
# base image to contain cryptography; retain its runtime user and dependencies.
RUN python -c 'from cryptography.fernet import Fernet; Fernet.generate_key()'
COPY copilot/account/pelanggan_copilot.py copilot/account/pelanggan_hasil.py \
     copilot/account/pelanggan_marketing.py copilot/account/marketing_social.py \
     copilot/account/marketing_worker.py copilot/account/akaun_pelayan.py \
     copilot/account/marketing_lifecycle.py /app/
