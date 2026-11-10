<?php

declare(strict_types=1);

/**
 * Security_Headers for the pages (public-lab Requirement 20). public/ is the
 * web root on the server and does not load src/, so the four pairs are repeated
 * here; a test keeps them equal to PoltergeistSwitch\TurnLoop\Http\SecurityHeaders.
 *
 * Prints nothing. Call it before any output. Never loosen the policy: move any
 * inline script or style to public/js/ or public/css/ instead.
 */
function ps_send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; "
        . 'font-src https://fonts.gstatic.com; '
        . "img-src 'self' data:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Strict-Transport-Security: max-age=31536000');
}
