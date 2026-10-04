# syntax=docker/dockerfile:1
# Frontend tooling image: Node LTS with corepack (pnpm).
FROM node:24-bookworm-slim

ARG UID=1000
ARG GID=1000

# Reuse the image's "node" user, remapped to the host UID/GID so files written
# through the ./frontend bind mount stay owned by you.
RUN groupmod --non-unique --gid "${GID}" node \
    && usermod --non-unique --uid "${UID}" --gid "${GID}" node \
    && chown -R node:node /home/node \
    && corepack enable \
    && mkdir -p /app && chown node:node /app

ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

USER node
WORKDIR /app
