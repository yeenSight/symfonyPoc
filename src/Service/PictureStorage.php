<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PictureStorage
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function resolvePath(?string $url): ?string
    {
        if ($url !== null && preg_match('~^/private/pictures/([a-f0-9]{32}\.[a-z0-9]+)$~', $url, $matches)) {
            $root = $this->projectDir.'/var/private/pictures';
            $relative = $matches[1];
        } elseif ($url !== null && str_starts_with($url, '/uploads/pictures/')) {
            $root = $this->projectDir.'/public/uploads/pictures';
            $relative = substr($url, strlen('/uploads/pictures/'));
            if (str_contains($relative, '..')) {
                return null;
            }
        } else {
            return null;
        }

        $rootPath = realpath($root);
        $path = realpath($root.'/'.$relative);
        if ($rootPath === false || $path === false || !str_starts_with($path, $rootPath.DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return $path;
    }
}
