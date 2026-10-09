<?php
declare(strict_types=1);
// Explicit MIME type avoids requiring changes to the site's Nginx mime map.
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile(__DIR__.'/manifest.webmanifest');
