<?php

declare(strict_types=1);

// Copy this file to config.local.php on the server and fill it in.
// config.local.php is ignored by git (see .gitignore): never commit it.
//
// All real values live OUTSIDE the repository and OUTSIDE the web root:
//   - the API key files <name>.key (e.g. anthropic.key, openai.key) and
//   - a db.php that returns the database dsn/user/password
// all live in the directory named by `secrets_dir` below. This file holds only
// the pointer to that directory — never a key, credential, or real path.
return [
    // Folder that holds the key files and db.php. Keep it outside the web root
    // and outside this repository. Placeholder only — set the real path in
    // config.local.php on the server.
    'secrets_dir' => '/path/outside/the/web/root',
];

// The db.php file inside secrets_dir returns the database credentials, e.g.:
//
//   <?php
//   return [
//       'dsn'      => 'mysql:host=localhost;dbname=poltergeist;charset=utf8mb4',
//       'user'     => 'db_user',
//       'password' => 'db_password',
//   ];
//
// Keep db.php outside the web root and outside this repository; never commit it.
