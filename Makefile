all: clean test coverage

clean:
	rm -rf build/artifacts/*

test:
	vendor/bin/phpunit --testsuite=php-lib-log-service $(TEST)

coverage:
	vendor/bin/phpunit --testsuite=php-lib-log-service --coverage-html=build/artifacts/coverage $(TEST)

coverage-show:
	open build/artifacts/coverage/index.html
