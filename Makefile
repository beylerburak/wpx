VERSION ?= 0.1.0
BINARY := wpx
BUILD_DIR := build
LDFLAGS := -ldflags "-X main.Version=$(VERSION)"

.PHONY: build clean test install lint

## Build the wpx CLI binary
build:
	go build $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY) ./cmd/wpx

## Install wpx to $GOPATH/bin
install:
	go install $(LDFLAGS) ./cmd/wpx

## Run Go tests
test:
	go test -v ./...

## Run the integration regression suite against a local WordPress + Elementor
## install. Requires `make build` first. Override WP_ROOT / WP_BIN as needed.
test-integration: build
	./tests/integration/regression.sh

## Run linters
lint:
	golangci-lint run ./...

## Clean build artifacts
clean:
	rm -rf $(BUILD_DIR)

## Cross-compile for all platforms
release:
	GOOS=darwin GOARCH=arm64 go build $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY)-darwin-arm64 ./cmd/wpx
	GOOS=darwin GOARCH=amd64 go build $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY)-darwin-amd64 ./cmd/wpx
	GOOS=linux GOARCH=amd64 go build $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY)-linux-amd64 ./cmd/wpx
	GOOS=linux GOARCH=arm64 go build $(LDFLAGS) -o $(BUILD_DIR)/$(BINARY)-linux-arm64 ./cmd/wpx

## Show help
help:
	@echo "wpx - Agent-Native CLI for WordPress + Elementor"
	@echo ""
	@echo "Available targets:"
	@echo "  build    - Build the CLI binary"
	@echo "  install  - Install to GOPATH/bin"
	@echo "  test     - Run tests"
	@echo "  lint     - Run linters"
	@echo "  clean    - Remove build artifacts"
	@echo "  release  - Cross-compile for all platforms"
