build: cf-llm-srt-translate.phar

cf-llm-srt-translate.phar: translate.php src/*.php llm-models.json composer.json vendor/autoload.php
	php -dphar.readonly=0 $$(composer config home)/vendor/bin/box compile

clean:
	rm -f cf-llm-srt-translate.phar

.PHONY: build clean
