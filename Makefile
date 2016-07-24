##
# This file is part of twigc.
#
# @author  dana geier <dana@dana.is>
# @license MIT

all: build

vendor:
	composer install

twigc.phar: vendor
	php bin/compile

build: twigc.phar

install: build
	cp twigc.phar /usr/local/bin/twigc

clean:
	rm -f twigc.phar

distclean: clean
	rm -rf vendor/

.PHONY: all build install clean distclean

