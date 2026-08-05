FROM node:22-alpine AS build

WORKDIR /app
COPY server.js ./

FROM node:22-alpine AS runtime

RUN addgroup -S app \
    && adduser -S -G app app

WORKDIR /app
COPY --from=build /app/server.js ./.next/standalone/server.js

USER app
EXPOSE 3000

CMD ["node", ".next/standalone/server.js"]
