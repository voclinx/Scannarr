# Build stage runs on the host platform (BUILDPLATFORM) to avoid QEMU issues
# with Node.js on arm64 — the output dist/ is platform-agnostic (static assets)
FROM --platform=$BUILDPLATFORM node:20-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
