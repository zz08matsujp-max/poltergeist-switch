<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop\Http;

/**
 * The four Security_Headers (public-lab Requirement 20). One definition for
 * the API; public/security_headers.php repeats the same four pairs because
 * public/ does not load src/. A test keeps the two equal.
 *
 * The policy must never be loosened: no 'unsafe-inline', no 'unsafe-eval' and
 * no added source. Move inline code to public/js/ or public/css/ instead.
 */
final class SecurityHeaders
{
    /**
     * @return array<string,string> header name => value
     */
    public static function all(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; "
                . "style-src 'self' https://fonts.googleapis.com; "
                . 'font-src https://fonts.gstatic.com; '
                . "img-src 'self' data:; connect-src 'self'; object-src 'none'; "
                . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Frame-Options'           => 'DENY',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
            'Strict-Transport-Security' => 'max-age=31536000',
        ];
    }
}
