<?php
declare(strict_types=1);
// If subdomain/docroot points to project root (not /web), send users to the app.
header('Location: web/', true, 302);
exit;
