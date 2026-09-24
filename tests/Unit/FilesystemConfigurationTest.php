<?php

namespace Tests\Unit;

use Tests\TestCase;

final class FilesystemConfigurationTest extends TestCase
{
    public function test_nas_mount_environment_values_override_local_storage_roots(): void
    {
        $localRoot = '/mnt/geoflow/storage/app/private';
        $publicRoot = '/mnt/geoflow/storage/app/public';
        $overrides = [
            'FILESYSTEM_LOCAL_ROOT' => $localRoot,
            'FILESYSTEM_PUBLIC_ROOT' => $publicRoot,
        ];
        $originalEnvironment = [];

        foreach ($overrides as $name => $value) {
            $originalEnvironment[$name] = [
                'getenv' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env_value' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server_value' => $_SERVER[$name] ?? null,
            ];

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            $filesystems = require config_path('filesystems.php');
        } finally {
            foreach ($originalEnvironment as $name => $original) {
                $original['getenv'] === false
                    ? putenv($name)
                    : putenv($name.'='.$original['getenv']);

                if ($original['env_exists']) {
                    $_ENV[$name] = $original['env_value'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($original['server_exists']) {
                    $_SERVER[$name] = $original['server_value'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }

        $this->assertSame('local', $filesystems['default']);
        $this->assertSame('local', $filesystems['disks']['local']['driver']);
        $this->assertSame('local', $filesystems['disks']['public']['driver']);
        $this->assertSame($localRoot, $filesystems['disks']['local']['root']);
        $this->assertSame($publicRoot, $filesystems['disks']['public']['root']);
    }
}
