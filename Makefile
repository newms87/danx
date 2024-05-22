VERSION ?= false

# Publish the composer package via git tag
publish:
	@if [ "$(VERSION)" = "false" ]; then \
		echo "Please provide a version number"; \
		exit 1; \
	fi
	git tag -a $(VERSION) -m "Release $(VERSION)"
	git push origin $(VERSION)

# Retrieves latest version from git tags, patches the version, and commits and pushes the change
patch:
	VERSION=$$(git tag | grep -Px '^[0-9]+\.[0-9]+\.[0-9]+' | sort -V | tail -n 1 | awk -F. '{print $$1"."$$2"."($$3+1)}') ; \
	git tag -a "$$VERSION" -m "Release $$VERSION" ; \
	git push origin "$$VERSION"


