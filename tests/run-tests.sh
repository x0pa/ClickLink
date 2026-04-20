#!/usr/bin/env sh
set -eu

php tests/test-compatibility.php
php tests/test-lifecycle.php
