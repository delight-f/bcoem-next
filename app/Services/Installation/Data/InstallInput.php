<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class InstallInput
{
    public function __construct(
        public DbCredentials $db,
        public string $appUrl,
        public string $adminName,
        public string $adminEmail,
        public string $adminPassword,
    ) {}

    public function admin(): AdminInput
    {
        return new AdminInput($this->adminName, $this->adminEmail, $this->adminPassword);
    }
}
