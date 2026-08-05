FROM node:22-alpine AS deps

WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

FROM node:22-alpine AS build

WORKDIR /app/frontend
COPY --from=deps /app/frontend/node_modules ./node_modules
COPY docs/openapi.yaml /app/docs/openapi.yaml
COPY frontend/ ./
RUN npm run build

FROM node:22-alpine AS runtime

RUN addgroup -S app \
    && adduser -S -G app app

WORKDIR /app
ENV NODE_ENV=production
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

COPY --from=build /app/frontend/public ./public
COPY --from=build /app/frontend/.next/standalone ./
COPY --from=build /app/frontend/.next/static ./.next/static

USER app
EXPOSE 3000

CMD ["node", "server.js"]
