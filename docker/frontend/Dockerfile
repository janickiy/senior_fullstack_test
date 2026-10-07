FROM node:24-bookworm-slim AS frontend

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./
RUN npm run build

FROM nginx:stable-alpine

COPY --from=frontend /app/dist/marketplace-frontend/browser/ /usr/share/nginx/html/
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
