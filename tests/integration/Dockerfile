FROM golang:1.21 AS builder
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY cmd ./cmd
COPY internal ./internal
RUN CGO_ENABLED=0 go build -ldflags "-X main.Version=integration" -o /out/wpx ./cmd/wpx

FROM wordpress:cli-php8.3
USER root
RUN set -eux; \
    if command -v apk >/dev/null 2>&1; then apk add --no-cache bash curl; \
    else apt-get update && apt-get install -y --no-install-recommends bash curl && rm -rf /var/lib/apt/lists/*; fi
COPY --from=builder /out/wpx /usr/local/bin/wpx
USER 33:33
ENTRYPOINT ["bash", "/workspace/tests/integration/docker-run.sh"]
