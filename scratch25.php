<?php
$hash = '$2y$10$aU8RIDDfKJwrFyT7bDdWZ.jn6tqJCjywSuht7J7wvJHp.KWz5xa/K';
$passwords = ['12345', '123456', '12345678', 'password', 'admin', 'admin123', 'yefferson', '123456789', 'fieles', '123'];
foreach ($passwords as $p) {
    if (password_verify($p, $hash)) {
        echo "Found: $p\n";
        break;
    }
}
