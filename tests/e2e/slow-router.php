<?php

// Router for `php -S`: serves the test feed after a delay, to provoke overlapping syncs.
sleep(4);
header('Content-Type: text/calendar');
readfile(getenv('CB_FEED_FILE'));
