<?php

/**
 * Nothing is served from this install.
 *
 * 410 rather than 404: the content genuinely exists, it is just not published
 * here. A crawler that reaches this by accident should not come back.
 */

declare(strict_types=1);

status_header(410);
nocache_headers();

echo 'This is a content management system. The website is elsewhere.';
