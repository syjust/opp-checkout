BOOTSTRAP_VERSION := 5.3.8
BOOTSTRAP_CDN := https://cdn.jsdelivr.net/npm/bootstrap@$(BOOTSTRAP_VERSION)/dist

.PHONY: bootstrap-local

bootstrap-local:
	mkdir -p public/vendor/bootstrap/css public/vendor/bootstrap/js
	curl -sS -o public/vendor/bootstrap/css/bootstrap.min.css $(BOOTSTRAP_CDN)/css/bootstrap.min.css
	curl -sS -o public/vendor/bootstrap/js/bootstrap.bundle.min.js $(BOOTSTRAP_CDN)/js/bootstrap.bundle.min.js
