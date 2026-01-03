.PHONY: phar clean help

# Default target
.DEFAULT_GOAL := help

# Variables
PHAR_NAME := phparch.phar
BUILD_DIR := build
STUB_FILE := $(BUILD_DIR)/stub.php

help: ## Show this help message
	@echo "Available targets:"
	@echo "  make phar    - Build the PHAR archive"
	@echo "  make clean   - Remove build artifacts"
	@echo "  make help    - Show this help message"

phar: $(PHAR_NAME) ## Build the PHAR archive

$(PHAR_NAME): $(STUB_FILE) build-phar.php
	@echo "Building PHAR archive..."
	@mkdir -p $(BUILD_DIR)
	@php -d phar.readonly=0 build-phar.php $(PHAR_NAME) $(STUB_FILE)
	@chmod +x $(PHAR_NAME)
	@echo "PHAR archive created: $(PHAR_NAME)"

$(STUB_FILE): bin/phparch build-stub.php
	@mkdir -p $(BUILD_DIR)
	@echo "Creating PHAR stub..."
	@php build-stub.php $(STUB_FILE)

clean: ## Remove build artifacts
	@echo "Cleaning build artifacts..."
	@rm -rf $(BUILD_DIR)
	@rm -f $(PHAR_NAME)
	@echo "Clean complete"
