VERSION ?= 0.1.0
BINARY := wpx
BUILD_DIR := build
LDFLAGS := -ldflags "-X main.Version=$(VERSION)"

.PHONY: build clean test test-integration test-integration-docker install lint package-plugin release

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

## Build a disposable WordPress + Elementor stack and run the full suite.
## Override WORDPRESS_VERSION / ELEMENTOR_VERSION to exercise another pair.
test-integration-docker: package-plugin
	./tests/integration/run-docker.sh

## Run linters
lint:
	golangci-lint run ./...

## Clean build artifacts
clean:
	rm -rf $(BUILD_DIR)

## Create the wp-admin-installable plugin archive.
package-plugin:
	VERSION=$(VERSION) ./scripts/package-plugin.sh

## Cross-compile for all platforms
release: package-plugin
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
	@echo "  test-integration-docker - Run integration tests in disposable containers"
	@echo "  lint     - Run linters"
	@echo "  clean    - Remove build artifacts"
	@echo "  package-plugin - Build an installable WordPress plugin zip"
	@echo "  release  - Cross-compile for all platforms"
