<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Collector\Php;
use Magenx\Platform\Model\Formatter;
use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Hardening rows: what the PHP user can write, and whether it can reach cron.
 *
 * Same shape as the OPcache and FPM row tests — the probes touch the filesystem
 * and the builders take what they found, so the builders can be driven with
 * literal facts and nothing here chmods anything.
 *
 * What is pinned is the policy, since that is the part a later edit could soften
 * without anything failing: tmp/, var/ and pub/media/ are the whole allowlist in
 * every deploy mode, generated/ and pub/static/ get no exemption for developer
 * mode, and a writable cron path is an error on its own — no process has to be
 * spawned to write a file.
 */
#[CoversClass(Php::class)]
class HardeningRowsTest extends TestCase
{
    /**
     * A correctly locked-down install: the three allowlisted paths writable and
     * nothing else.
     *
     * @param array $overrides Merged over the top level.
     * @return array
     */
    private function filesystem(array $overrides = []): array
    {
        return $overrides + [
            'user' => 'www-data',
            'uid' => 33,
            'allowed' => ['var/' => true, 'pub/media/' => true, 'tmp/' => true],
            'other' => [
                '.' => false,
                'app/' => false,
                'app/etc/' => false,
                'app/etc/env.php' => false,
                'generated/' => false,
                'pub/' => false,
                'pub/static/' => false,
                'vendor/' => false,
            ],
            'readable' => ['auth.json' => false, '.git/' => false, '~/.ssh/' => false],
            'home' => '/var/www',
        ];
    }

    /**
     * A PHP user with no way to reach cron at all.
     *
     * @param array $overrides Merged over the top level.
     * @return array
     */
    private function cron(array $overrides = []): array
    {
        return $overrides + [
            'user' => 'www-data',
            'spawnable' => [],
            'crontab' => '',
            'denied' => null,
            'writable' => [],
        ];
    }

    /**
     * Rows keyed by label, built by one of the two builders.
     *
     * @param string $builder
     * @param array $facts
     * @return array<string, array>
     */
    private function rowsFor(string $builder, array $facts): array
    {
        $reflection = new ReflectionClass(Php::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        foreach (['formatter' => new Formatter(), 'status' => new Status()] as $name => $collaborator) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($collector, $collaborator);
        }

        $result = new Result(new Status());

        $method = $reflection->getMethod($builder);
        $method->setAccessible(true);
        $method->invokeArgs($collector, [$result, 'Hardening', $facts]);

        $rows = [];
        foreach ($result->toArray()['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row;
            }
        }

        return $rows;
    }

    public function testALockedDownInstallReportsTheAllowlistAndNothingElse(): void
    {
        $rows = $this->rowsFor('buildFilesystemRows', $this->filesystem());

        $this->assertSame(['Runs As', 'Writable Paths', 'Unexpected Writable'], array_keys($rows));
        $this->assertSame('www-data (uid 33)', $rows['Runs As']['value']);
        $this->assertSame(Status::INFO, $rows['Runs As']['status']);
        $this->assertSame('var/, pub/media/, tmp/', $rows['Writable Paths']['value']);
        $this->assertSame(Status::OK, $rows['Writable Paths']['status']);
        $this->assertSame('None', $rows['Unexpected Writable']['value']);
        $this->assertSame(Status::OK, $rows['Unexpected Writable']['status']);
    }

    public function testAnythingWritableOutsideTheAllowlistIsAnErrorAndIsNamed(): void
    {
        $facts = $this->filesystem();
        $facts['other']['app/etc/'] = true;
        $facts['other']['app/etc/env.php'] = true;
        $facts['other']['vendor/'] = true;

        $row = $this->rowsFor('buildFilesystemRows', $facts)['Unexpected Writable'];

        $this->assertSame(Status::ERROR, $row['status']);
        // The row says which paths to chmod, directories printed as such by the
        // probe that found them.
        $this->assertSame('app/etc/, app/etc/env.php, vendor/', $row['value']);
    }

    public function testBuildOutputGetsNoDeveloperModeExemption(): void
    {
        $facts = $this->filesystem();
        $facts['other']['generated/'] = true;
        $facts['other']['pub/static/'] = true;

        $row = $this->rowsFor('buildFilesystemRows', $facts)['Unexpected Writable'];

        // Writable in developer mode is the usual state and still the finding:
        // both are build output and belong to the deploy user.
        $this->assertSame(Status::ERROR, $row['status']);
        $this->assertSame('generated/, pub/static/', $row['value']);
    }

    public function testAWritableDocumentRootIsReported(): void
    {
        $facts = $this->filesystem();
        $facts['other']['.'] = true;

        $row = $this->rowsFor('buildFilesystemRows', $facts)['Unexpected Writable'];

        $this->assertSame(Status::ERROR, $row['status']);
        $this->assertSame('.', $row['value']);
    }

    public function testAnAllowlistedPathThatIsNotWritableIsAnErrorToo(): void
    {
        $facts = $this->filesystem();
        $facts['allowed']['var/'] = false;

        $row = $this->rowsFor('buildFilesystemRows', $facts)['Writable Paths'];

        // Magento cannot run without it, so this is a fault in the other
        // direction rather than a hardening win.
        $this->assertSame(Status::ERROR, $row['status']);
        $this->assertSame('pub/media/, tmp/', $row['value']);
        $this->assertStringContainsString('var/', $row['hint']);
    }

    public function testAnInstallWithoutARootTmpIsNotPenalizedForIt(): void
    {
        $facts = $this->filesystem();
        unset($facts['allowed']['tmp/']);

        $row = $this->rowsFor('buildFilesystemRows', $facts)['Writable Paths'];

        $this->assertSame(Status::OK, $row['status']);
        $this->assertSame('var/, pub/media/', $row['value']);
    }

    public function testAPoolRunningAsRootIsAnErrorAndSaysTheRowsBelowStopMeaningAnything(): void
    {
        $rows = $this->rowsFor('buildFilesystemRows', $this->filesystem(['user' => 'root', 'uid' => 0]));

        $this->assertSame('root (uid 0)', $rows['Runs As']['value']);
        $this->assertSame(Status::ERROR, $rows['Runs As']['status']);
        $this->assertStringContainsString('every path on the host tests writable', $rows['Runs As']['hint']);
    }

    public function testAHostWithoutPosixStillNamesTheUser(): void
    {
        $rows = $this->rowsFor('buildFilesystemRows', $this->filesystem(['user' => 'www-data', 'uid' => null]));

        $this->assertSame('www-data', $rows['Runs As']['value']);
        $this->assertSame(Status::INFO, $rows['Runs As']['status']);
    }

    public function testNoWayToReachCronIsTheHealthyReadingAndSaysWhy(): void
    {
        $rows = $this->rowsFor('buildCronRows', $this->cron());

        $this->assertSame(['Process Execution', 'Cron Access'], array_keys($rows));
        $this->assertSame('Disabled', $rows['Process Execution']['value']);
        $this->assertSame(Status::OK, $rows['Process Execution']['status']);
        $this->assertSame('No access (no process-spawning function is enabled)', $rows['Cron Access']['value']);
        $this->assertSame(Status::OK, $rows['Cron Access']['status']);
    }

    public function testProcessSpawningFunctionsAreNamedAndWarnedAbout(): void
    {
        $facts = $this->cron(['spawnable' => ['exec', 'proc_open']]);
        $row = $this->rowsFor('buildCronRows', $facts)['Process Execution'];

        $this->assertSame('Available: exec, proc_open', $row['value']);
        $this->assertSame(Status::WARN, $row['status']);
    }

    public function testAWritableCronPathIsAnErrorEvenWithEveryFunctionDisabled(): void
    {
        $row = $this->rowsFor('buildCronRows', $this->cron(['writable' => ['/etc/cron.d']]))['Cron Access'];

        // Dropping a file into /etc/cron.d spawns no process of its own, so
        // disable_functions does not cover this.
        $this->assertSame('Writable: /etc/cron.d', $row['value']);
        $this->assertSame(Status::ERROR, $row['status']);
    }

    public function testAReachableCrontabIsAnError(): void
    {
        $row = $this->rowsFor(
            'buildCronRows',
            $this->cron(['spawnable' => ['exec'], 'crontab' => '/usr/bin/crontab'])
        )['Cron Access'];

        $this->assertSame('crontab reachable (/usr/bin/crontab)', $row['value']);
        $this->assertSame(Status::ERROR, $row['status']);
    }

    public function testACrontabThisUserIsDeniedIsNotAWayIn(): void
    {
        $row = $this->rowsFor(
            'buildCronRows',
            $this->cron(['spawnable' => ['exec'], 'crontab' => '/usr/bin/crontab', 'denied' => true])
        )['Cron Access'];

        $this->assertSame('No access (denied by cron.allow / cron.deny)', $row['value']);
        $this->assertSame(Status::OK, $row['status']);
    }

    public function testACrontabBinaryWithNoWayToRunItIsNotAWayInEither(): void
    {
        $row = $this->rowsFor('buildCronRows', $this->cron(['crontab' => '/usr/bin/crontab']))['Cron Access'];

        $this->assertSame('No access (no process-spawning function is enabled)', $row['value']);
        $this->assertSame(Status::OK, $row['status']);
    }

    public function testAnImageWithoutCrontabIsNotAWayIn(): void
    {
        $row = $this->rowsFor('buildCronRows', $this->cron(['spawnable' => ['exec', 'proc_open']]))['Cron Access'];

        $this->assertSame('No access (no crontab binary is executable)', $row['value']);
        $this->assertSame(Status::OK, $row['status']);
    }

    public function testAnInstallWhereNothingSensitiveCanBeOpenedIsTheHealthyReading(): void
    {
        $rows = $this->rowsFor('buildReadabilityRows', $this->filesystem());

        $this->assertSame(['Unexpected Readable'], array_keys($rows));
        $this->assertSame('None', $rows['Unexpected Readable']['value']);
        $this->assertSame(Status::OK, $rows['Unexpected Readable']['status']);
    }

    public function testCredentialsAndSourceThatCanBeOpenedAreAnErrorAndAreNamed(): void
    {
        $facts = $this->filesystem();
        $facts['readable']['auth.json'] = true;
        $facts['readable']['.git/'] = true;

        $row = $this->rowsFor('buildReadabilityRows', $facts)['Unexpected Readable'];

        // No write needed: auth.json is the Marketplace keys and .git/ is the
        // source with its history.
        $this->assertSame(Status::ERROR, $row['status']);
        $this->assertSame('auth.json, .git/', $row['value']);
    }

    public function testTheHomeDotfilesAreReportedWithTheirShellShorthand(): void
    {
        $facts = $this->filesystem();
        $facts['readable']['~/.ssh/'] = true;
        $facts['readable']['~/.bash_history'] = true;

        $row = $this->rowsFor('buildReadabilityRows', $facts)['Unexpected Readable'];

        $this->assertSame(Status::ERROR, $row['status']);
        $this->assertSame('~/.ssh/, ~/.bash_history', $row['value']);
    }

    public function testAProbeThatMatchedNothingStillRendersTheRow(): void
    {
        $facts = $this->filesystem();
        unset($facts['readable']);

        $row = $this->rowsFor('buildReadabilityRows', $facts)['Unexpected Readable'];

        $this->assertSame('None', $row['value']);
        $this->assertSame(Status::OK, $row['status']);
    }

    /**
     * @param string $name
     * @param bool $expected
     */
    #[DataProvider('rootNames')]
    public function testTheRootPatternsCoverTheDeploymentFamily(string $name, bool $expected): void
    {
        $this->assertSame($expected, $this->matchesPattern($name, 'UNREADABLE_ROOT_PATTERNS'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function rootNames(): array
    {
        return [
            'the composer credentials' => ['auth.json', true],
            'the repository' => ['.git', true],
            'the workflows' => ['.github', true],
            'a deploy directory' => ['deploy', true],
            'a deploy script' => ['deploy.sh', true],
            'a per-environment deploy script' => ['deploy-prod.sh', true],
            'a sample is not the credentials' => ['auth.json.sample', false],
            'the media directory' => ['pub', false],
            'the writable var' => ['var', false],
        ];
    }

    /**
     * @param string $name
     * @param bool $expected
     */
    #[DataProvider('homeNames')]
    public function testTheHomePatternsCoverTheShellFamily(string $name, bool $expected): void
    {
        $this->assertSame($expected, $this->matchesPattern($name, 'UNREADABLE_HOME_PATTERNS'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function homeNames(): array
    {
        return [
            'ssh keys' => ['.ssh', true],
            'composer auth' => ['.composer', true],
            'the config tree' => ['.config', true],
            'the data tree' => ['.local', true],
            'the cache tree' => ['.cache', true],
            'the shell rc' => ['.bashrc', true],
            'the shell history' => ['.bash_history', true],
            'the profile is not the shell\'s' => ['.profile', false],
            'a public key pasted loose' => ['id_rsa.pub', false],
        ];
    }

    /**
     * One name against one of the collector's own pattern sets.
     *
     * @param string $name
     * @param string $constant
     * @return bool
     */
    private function matchesPattern(string $name, string $constant): bool
    {
        $reflection = new ReflectionClass(Php::class);
        $collector = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('matchesAny');
        $method->setAccessible(true);

        return (bool) $method->invokeArgs($collector, [$name, $reflection->getConstant($constant)]);
    }
}
