FROM golang:1.22-alpine AS build

WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd ./cmd
COPY internal ./internal
RUN CGO_ENABLED=0 GOOS=linux go build -o /out/worker ./cmd/expirer

FROM gcr.io/distroless/static-debian12:nonroot AS runtime

COPY --from=build /out/worker /worker
USER nonroot:nonroot
EXPOSE 8081

ENTRYPOINT ["/worker"]
