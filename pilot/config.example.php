<?php
// Copy this file to config.local.php on the server and fill it in. config.local.php is ignored by git: never commit it.
return [
    // folder that holds anthropic.key and openai.key; keep it outside the site's web root
    'secrets_dir'  => '/path/outside/the/web/root',
    // SHA-256 (hex) of the passphrase for ?t=..., e.g.  php -r "echo hash('sha256', 'your passphrase');"
    'token_sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
];
