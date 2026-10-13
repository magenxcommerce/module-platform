<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * The packages this installation locked, and the ones it asked for by name.
 *
 * composer.lock is what was actually installed, with each package's own
 * requirements; composer.json's `require` is the short list someone chose. The
 * first is what advisories and an upgrade's constraints are checked against,
 * the second is what "outdated" is worth reporting for — a transitive
 * dependency is updated by updating whatever pulled it in.
 *
 * Development packages (`packages-dev`) are left out: a production install
 * runs with --no-dev, and an advisory against PHPUnit is not a storefront risk.
 */
class ComposerProject
{
    private ProjectFiles $files;

    /**
     * @param ProjectFiles $files
     */
    public function __construct(ProjectFiles $files)
    {
        $this->files = $files;
    }

    /**
     * Locked production packages as name => `{name, version, type, require}`.
     *
     * @return array{packages: array<string, array<string, mixed>>, state: string, path: string}
     */
    public function lockedPackages(): array
    {
        $lock = $this->files->readJson('composer.lock');
        $packages = [];
        foreach ((array) ($lock['data']['packages'] ?? []) as $package) {
            if (!is_array($package)
                || !is_string($package['name'] ?? null)
                || !is_string($package['version'] ?? null)
            ) {
                continue;
            }
            $packages[strtolower($package['name'])] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'type' => is_string($package['type'] ?? null) ? $package['type'] : 'library',
                'require' => array_filter(
                    is_array($package['require'] ?? null) ? $package['require'] : [],
                    'is_string'
                ),
            ];
        }

        return ['packages' => $packages, 'state' => $lock['state'], 'path' => $lock['path']];
    }

    /**
     * composer.json `require`, without the platform entries (php, ext-*, lib-*,
     * composer-*) that are not packages anyone can update.
     *
     * @return array{require: array<string, string>, state: string, path: string}
     */
    public function rootRequirements(): array
    {
        $root = $this->files->readJson('composer.json');
        $require = [];
        foreach ((array) ($root['data']['require'] ?? []) as $name => $constraint) {
            $name = strtolower((string) $name);
            if (!is_string($constraint) || !str_contains($name, '/')) {
                continue;
            }
            $require[$name] = $constraint;
        }

        return ['require' => $require, 'state' => $root['state'], 'path' => $root['path']];
    }
}
