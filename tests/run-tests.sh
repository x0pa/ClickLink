#!/usr/bin/env sh
set -eu

php tests/test-compatibility.php
php tests/test-admin-page.php
php tests/test-dashboard-widget.php
php tests/test-installer.php
php tests/test-lifecycle.php
php tests/test-post-save-linker.php
