##
# This file is part of twigc.
#
# @author  dana <dana@dana.is>
# @license MIT

prefix ?= /usr/local
bindir ?= $(prefix)/bin

all:   build
build: phar
phar:  clean twigc.phar
test:  test-unit test-integration

help:
	@echo 'Available targets:'
	@echo 'all ................ Equivalent to `build`'
	@echo 'build .............. Equivalent to `vendor`'
	@echo 'install ............ Install phar to `/usr/local/bin/twigc`'
	@echo 'clean .............. Remove phar'
	@echo 'distclean .......... Remove phar and vendor directory'
	@echo 'phar ............... Equivalent to `twigc.phar`'
	@echo 'test ............... Run unit and integration tests'
	@echo 'test-integration ... Run integration tests against phar'
	@echo 'test-unit .......... Run unit tests against source'
	@echo 'twigc.phar ......... Build phar'
	@echo 'vendor ............. Install vendor directory via Composer'

vendor:
	composer install

twigc.phar:
	composer install -q --no-dev
	php -d phar.readonly=0 bin/compile
	composer install -q

test-unit: vendor
	vendor/bin/phpunit

test-integration: twigc.phar
	./twigc.phar --help | grep -q -- --version
	echo 'hello {{ name }}' | ./twigc.phar -j '{ "name": "foo" }' | grep -qF 'hello foo'
	echo 'hello {{ name }}' | ./twigc.phar -p name=foo | grep -qF 'hello foo'

install: twigc.phar
	cp twigc.phar $(DESTDIR)$(bindir)/twigc

clean:
	rm -f twigc.phar

distclean: clean
	rm -rf vendor/

.PHONY: all build clean distclean help install phar test test-integration test-unit
