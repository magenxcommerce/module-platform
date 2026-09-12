<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Test\Unit\Standalone;

use Magenx\Platform\Model\Metric\Result;
use Magenx\Platform\Model\Metric\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Result::class)]
class ResultTest extends TestCase
{
    private function newResult(): Result
    {
        return new Result(new Status());
    }

    public function testAnEmptyResultIsInformationalRatherThanOk(): void
    {
        $payload = $this->newResult()->toArray();

        $this->assertSame(Status::INFO, $payload['status']);
        $this->assertSame([], $payload['sections']);
        $this->assertSame('', $payload['summary']);
    }

    public function testSectionsKeepInsertionOrderAndRollUpTheirOwnRowsOnly(): void
    {
        $result = $this->newResult();
        $result->add('Connections', 'Threads', '4', Status::OK);
        $result->add('Connections', 'Refused', '1', Status::ERROR);
        $result->add('Queries', 'Slow', '0', Status::OK);

        $payload = $result->toArray();

        $this->assertCount(2, $payload['sections']);
        $this->assertSame('Connections', $payload['sections'][0]['label']);
        $this->assertSame(Status::ERROR, $payload['sections'][0]['status']);
        $this->assertCount(2, $payload['sections'][0]['rows']);

        $this->assertSame('Queries', $payload['sections'][1]['label']);
        // The error next door must not bleed into this section. It rolls up to
        // INFO rather than OK because the rollup seeds at INFO and worst()
        // ranks INFO and OK equally, keeping whichever it saw first — see
        // testASectionOfHealthyRowsRollsUpToInfo below.
        $this->assertSame(Status::INFO, $payload['sections'][1]['status']);

        $this->assertSame(Status::ERROR, $payload['status']);
    }

    public function testTheTabStatusIsTheWorstOfEverySection(): void
    {
        $result = $this->newResult();
        $result->add('A', 'row', 'value', Status::OK);
        $result->add('B', 'row', 'value', Status::WARN);
        $result->add('C', 'row', 'value', Status::UNAVAILABLE);

        $this->assertSame(Status::UNAVAILABLE, $result->toArray()['status']);
    }

    public function testAnOverrideWinsOverTheRows(): void
    {
        // What a collector does when the backend could not be reached at all
        // and the rows it did manage cannot speak for it.
        $result = $this->newResult();
        $result->add('Cluster', 'Endpoint', 'http://opensearch:9200', Status::OK);
        $result->setStatus(Status::UNAVAILABLE);

        $payload = $result->toArray();

        $this->assertSame(Status::UNAVAILABLE, $payload['status']);
        // The override colours the tab without rewriting what the rows say.
        $this->assertSame(Status::INFO, $payload['sections'][0]['status']);
        $this->assertSame(Status::OK, $payload['sections'][0]['rows'][0]['status']);
    }

    public function testASectionOfHealthyRowsRollsUpToInfo(): void
    {
        // Surprising enough to pin. The rollup seeds at INFO and worst() ranks
        // INFO and OK equally, returning the left-hand side on a tie, so a
        // section whose rows are all OK reports INFO. Nothing downstream can
        // tell the difference — the stylesheet paints both the same green, and
        // the badge is blank for both — but a reader of this code would
        // otherwise expect OK.
        $result = $this->newResult();
        $result->add('Connections', 'Threads', '4', Status::OK);
        $result->add('Connections', 'Refused', '0', Status::OK);

        $payload = $result->toArray();

        $this->assertSame(Status::INFO, $payload['sections'][0]['status']);
        $this->assertSame(Status::INFO, $payload['status']);
    }

    public function testToArrayRollsRowsUpThroughSectionsIntoTheTab(): void
    {
        // toArray() rolls the status up in the same pass it serializes the rows,
        // and it is the only place that rollup lives — a second implementation
        // to cross-check it against is a second implementation to keep in step.
        // So this pins the answer itself: the tab takes the worst of its
        // sections, and each section the worst of its rows.
        $result = $this->newResult();
        $result->add('A', 'row', 'value', Status::OK);
        $result->add('A', 'row', 'value', Status::WARN);
        $result->add('B', 'row', 'value', Status::INFO);

        $payload = $result->toArray();

        $this->assertSame(Status::WARN, $payload['sections'][0]['status']);
        $this->assertSame(Status::INFO, $payload['sections'][1]['status']);
        $this->assertSame(Status::WARN, $payload['status']);
    }

    public function testAnOverrideBeatsTheRowsItIsSetOver(): void
    {
        // A collector that could not reach its backend says so outright, and
        // that verdict has to survive whatever rows it managed to add first.
        $result = $this->newResult();
        $result->add('A', 'row', 'value', Status::WARN);
        $result->setStatus(Status::ERROR);

        $payload = $result->toArray();

        $this->assertSame(Status::ERROR, $payload['status']);
        // Only the tab is overridden; the section still reports what it measured.
        $this->assertSame(Status::WARN, $payload['sections'][0]['status']);
    }

    public function testRowsCarryTheirLabelValueStatusAndHint(): void
    {
        $result = $this->newResult();
        $result->add('Section', 'Label', 'Value', Status::WARN, 'Hint');

        $this->assertSame(
            ['label' => 'Label', 'value' => 'Value', 'status' => Status::WARN, 'hint' => 'Hint'],
            $result->toArray()['sections'][0]['rows'][0]
        );
    }

    public function testSummaryIsCarriedThrough(): void
    {
        $this->assertSame(
            'MariaDB 10.11, up 3d 4h',
            $this->newResult()->setSummary('MariaDB 10.11, up 3d 4h')->toArray()['summary']
        );
    }
}
