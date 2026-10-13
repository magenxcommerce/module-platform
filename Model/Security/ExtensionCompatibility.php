<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * Which installed extensions a Magento release would refuse.
 *
 * Upgrading Magento moves every magento/* module to the version the release
 * pins and every library to the range it allows. An extension whose own
 * `require` excludes that — `"magento/framework": "102.0.*"` against a release
 * pinning 103.0.9, `"guzzlehttp/guzzle": "^6.0"` against one requiring ^7.5 —
 * makes `composer update` fail until the extension is updated or removed. That
 * is the same check composer's solver would make, run here against the
 * release's published manifest instead of against a working tree.
 *
 * Only packages outside Magento's own set are checked. A magento/* package, or
 * a library the release itself requires, moves with the release; it is the
 * third-party extensions that do not.
 */
class ExtensionCompatibility
{
    private Constraints $constraints;

    /**
     * @param Constraints $constraints
     */
    public function __construct(Constraints $constraints)
    {
        $this->constraints = $constraints;
    }

    /**
     * @param array<string, array<string, mixed>> $packages {@see ComposerProject::lockedPackages()}
     * @param array<string, string> $releaseRequire The release metapackage's `require`.
     * @return array{checked: int, conflicts: array<int, array<string, string>>}
     */
    public function check(array $packages, array $releaseRequire): array
    {
        $release = array_change_key_case($releaseRequire, CASE_LOWER);
        $checked = 0;
        $conflicts = [];

        foreach ($packages as $name => $package) {
            if (str_starts_with($name, 'magento/') || isset($release[$name])) {
                continue;
            }
            $checked++;

            foreach ((array) $package['require'] as $dependency => $constraint) {
                $dependency = strtolower((string) $dependency);
                $pinned = $release[$dependency] ?? null;
                if ($pinned === null || $dependency === 'php' || !is_string($constraint)) {
                    continue;
                }

                $fits = $this->constraints->isExact($pinned)
                    ? $this->constraints->satisfies($pinned, $constraint)
                    : $this->constraints->intersect($constraint, $pinned);
                if ($fits === false) {
                    $conflicts[] = [
                        'package' => (string) $package['name'],
                        'version' => (string) $package['version'],
                        'requires' => $dependency,
                        'constraint' => $constraint,
                        'release_requires' => $pinned,
                    ];
                }
            }
        }

        return ['checked' => $checked, 'conflicts' => $conflicts];
    }
}
