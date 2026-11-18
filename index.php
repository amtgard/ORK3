<?php

// Bare-domain visits land on the ORK UI in every environment. Prod's config
// carries a fixed HTTP_UI; the dev config (local docker, staging, the NAS)
// builds it from the request's Host header and X-Forwarded-Proto, so the
// redirect follows whatever hostname fronts the container.
require_once __DIR__ . '/config/bootstrap.php';
header("HTTP/1.1 302 Moved Temporarily");
header("Location: " . HTTP_UI);
