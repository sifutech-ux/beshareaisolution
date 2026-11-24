# Build from the verified, currently deployed account image. Never build over IPO.
# docker build -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=<verified-image> .
ARG ACCOUNT_BASE_IMAGE
FROM ${ACCOUNT_BASE_IMAGE}

# This release extends the 1e980edf Marketing account layer. A newer baseline
# must be reviewed and these expectations intentionally updated before release.
RUN echo 'bf4ee5fe170955833ec5c552a5d607a6135e635f086dd55f03c5412a66dc1b7c  /app/pelanggan_copilot.py' | sha256sum -c - \
 && echo '064377cd793c5ad8750a4cde743f31d50f72719631d039af533c7b6f83d736b7  /app/pelanggan_akaun.py' | sha256sum -c - \
 && echo 'c24fab7943cc48c78e0a4353f13cdf02c25026179fe652ad4f2417ff75dbdedb  /app/akaun_pelayan.py' | sha256sum -c -

# Connections fail closed without authenticated encryption. Require the chosen
# base image to contain cryptography; retain its runtime user and dependencies.
RUN python -c 'from cryptography.fernet import Fernet; Fernet.generate_key()'
COPY copilot/account/pelanggan_copilot.py copilot/account/pelanggan_hasil.py \
     copilot/account/pelanggan_kuota.py \
     copilot/account/pelanggan_marketing.py copilot/account/marketing_social.py \
     copilot/account/marketing_facebook_ads.py copilot/account/marketing_post_ads.py copilot/account/marketing_ad_simulation.py copilot/account/marketing_worker.py copilot/account/akaun_pelayan.py \
     copilot/account/marketing_lifecycle.py copilot/account/marketing_groups.py \
     copilot/account/marketing_group_worker.py copilot/account/marketing_telegram_discovery.py /app/
