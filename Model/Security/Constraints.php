<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Composer\Semver\Comparator;
use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Composer's own version and constraint rules, so nothing here reinvents them.
 *
 * Advisory ranges (">=8.0.0,<8.0.1|<7.15.2"), package requirements ("^103.0")
 * and release pins ("103.0.9") are all Composer constraints, and composer/semver
 * is already installed in every Magento — magento/framework depends on it. The
 * one thing added is that a value Composer cannot parse answers null, "cannot
 * tell", rather than throwing into a tab or reading as a match.
 */
class Constraints
{
    private VersionParser $parser;

    /**
     * Constructed here rather than injected: VersionParser holds no state and
     * no configuration, and a Magento preference for it would be a surprise.
     */
    public function __construct()
    {
        $this->parser = new VersionParser();
    }

    /**
     * Whether a concrete version satisfies a constraint.
     *
     * @param string $version
     * @param string $constraint
     * @return bool|null Null when either side cannot be parsed, e.g. a dev branch.
     */
    public function satisfies(string $version, string $constraint): ?bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Whether any version could satisfy both constraints at once — the
     * question composer's solver asks of two packages requiring the same one.
     *
     * @param string $left
     * @param string $right
     * @return bool|null
     */
    public function intersect(string $left, string $right): ?bool
    {
        try {
            return Intervals::haveIntersections(
                $this->parser->parseConstraints($left),
                $this->parser->parseConstraints($right)
            );
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Whether a version names one release rather than a range: "103.0.9" and
     * "2.4.9-p1" do, "^103.0" and "~8.3.0||~8.4.0" do not.
     *
     * @param string $value
     * @return bool
     */
    public function isExact(string $value): bool
    {
        try {
            $this->parser->normalize($value);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * The newest stable version in a list, or null if none is stable.
     *
     * @param string[] $versions
     * @return string|null
     */
    public function newestStable(array $versions): ?string
    {
        $stable = [];
        foreach ($versions as $version) {
            if (!is_string($version) || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
                continue;
            }
            try {
                if (VersionParser::parseStability($version) === 'stable') {
                    $this->parser->normalize($version);
                    $stable[] = $version;
                }
            } catch (\UnexpectedValueException) {
                continue;
            }
        }

        return $stable === [] ? null : Semver::rsort($stable)[0];
    }

    /**
     * @param string $left
     * @param string $right
     * @return bool|null
     */
    public function greaterThan(string $left, string $right): ?bool
    {
        try {
            return Comparator::greaterThan($this->parser->normalize($left), $this->parser->normalize($right));
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Whether two versions differ in their first number.
     *
     * @param string $left
     * @param string $right
     * @return bool
     */
    public function isMajorApart(string $left, string $right): bool
    {
        $major = static fn (string $v): string => (string) preg_replace('/^v?(\d+).*$/i', '$1', $v);

        return $major($left) !== $major($right);
    }
}
