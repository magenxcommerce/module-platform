<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

/**
 * Compares a measured service version with the versions a release lists.
 *
 * The lists come from magento.watch and mirror Adobe's system requirements,
 * which are the versions a release was *tested* with rather than a range: a
 * release listing OpenSearch "3" was tested on 3.x, and says nothing either
 * way about 2.19. So the comparison answers "is this one of the listed lines,
 * and if not, which side of them is it on", never "will it work".
 *
 * An entry matches by prefix of dotted segments: "8.4" covers 8.4.22, "3"
 * covers 3.1.0, and "11.4" does not cover 11.40. Entries that are not a
 * version at all — "supported", "redis-7.1" — are kept out of the ordering
 * rather than guessed at.
 */
class VersionRequirements
{
    /** The running version is one of the listed lines. */
    public const SUPPORTED = 'supported';

    /** Below every listed line. */
    public const OLDER = 'older_than_tested';

    /** Above every listed line. */
    public const NEWER = 'newer_than_tested';

    /** Between listed lines without matching any of them. */
    public const NOT_LISTED = 'not_listed';

    /** No entry is a version, so there is nothing to compare against. */
    public const NOT_COMPARABLE = 'not_comparable';

    /**
     * @param string $installed A measured version, e.g. "2.19.5".
     * @param string[] $required The release's listed versions for the same component.
     * @return string One of the verdict constants.
     */
    public function compare(string $installed, array $required): string
    {
        $installed = $this->normalize($installed);
        $numeric = array_values(array_filter(
            array_map(fn ($entry): ?string => is_string($entry) ? $this->normalize($entry) : null, $required),
            static fn (?string $entry): bool => $entry !== null
        ));

        if ($installed === null || $numeric === []) {
            return self::NOT_COMPARABLE;
        }

        foreach ($numeric as $entry) {
            if ($this->isWithin($installed, $entry)) {
                return self::SUPPORTED;
            }
        }

        usort($numeric, 'version_compare');
        if (version_compare($installed, $numeric[0], '<')) {
            return self::OLDER;
        }
        if (version_compare($installed, $numeric[count($numeric) - 1], '>')) {
            return self::NEWER;
        }

        return self::NOT_LISTED;
    }

    /**
     * The leading dotted number of a version string, or null if it has none.
     *
     * "11.4.10-MariaDB-ubu2404" becomes "11.4.10" and "Opensearch 2.19.5"
     * becomes "2.19.5"; "supported" and "redis-7.1" become null, because a
     * number that is not where a version starts is not this component's
     * version.
     *
     * @param string $version
     * @return string|null
     */
    public function normalize(string $version): ?string
    {
        if (preg_match('/^\D{0,20}?(\d+(?:\.\d+)*)/', trim($version), $match) !== 1) {
            return null;
        }
        // "redis-7.1" names another product's line; only a bare version, or a
        // product name followed by whitespace, is taken as this component's.
        $prefix = substr(trim($version), 0, (int) strpos(trim($version), $match[1]));
        if ($prefix !== '' && preg_match('/^[A-Za-z]+\s+$/', $prefix) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Whether every segment of the listed entry is a leading segment of the
     * installed version.
     *
     * @param string $installed
     * @param string $entry
     * @return bool
     */
    private function isWithin(string $installed, string $entry): bool
    {
        $have = explode('.', $installed);
        $want = explode('.', $entry);
        if (count($want) > count($have)) {
            return false;
        }

        foreach ($want as $index => $segment) {
            if ((int) $have[$index] !== (int) $segment) {
                return false;
            }
        }

        return true;
    }
}
