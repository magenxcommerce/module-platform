<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Metric;

/**
 * What one collector has to say about one backend.
 *
 * Sections keep their insertion order, and a tab's status is the worst status
 * among its rows unless the collector overrides it — which it does when the
 * backend could not be reached at all and there are no rows to speak for it.
 */
class Result
{
    private Status $status;

    private string $summary = '';

    private ?string $overrideStatus = null;

    /**
     * @var array<string, Row[]>
     */
    private array $sections = [];

    /**
     * @param Status $status
     */
    public function __construct(Status $status)
    {
        $this->status = $status;
    }

    /**
     * A one-line headline for the tab, shown next to the status dot.
     *
     * @param string $summary
     * @return $this
     */
    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * Force the tab status regardless of the rows.
     *
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        $this->overrideStatus = $status;

        return $this;
    }

    /**
     * @param string $section
     * @param Row $row
     * @return $this
     */
    public function addRow(string $section, Row $row): self
    {
        $this->sections[$section][] = $row;

        return $this;
    }

    /**
     * @param string $section
     * @param string $label
     * @param string $value
     * @param string $status
     * @param string $hint
     * @return $this
     */
    public function add(
        string $section,
        string $label,
        string $value,
        string $status = Status::INFO,
        string $hint = ''
    ): self {
        return $this->addRow($section, new Row($label, $value, $status, $hint));
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        // One walk, not two. A section's status is the worst of its rows and the
        // tab's is the worst of its sections, so rolling both up here costs the
        // same pass the rows are serialized in. It is also the ONLY place the
        // rollup lives, deliberately: a separate accessor that walked the
        // sections again to answer the same question would be a second copy of
        // this logic, free to drift from what the tab actually renders, and
        // reachable by nobody — the payload is what the endpoint returns.
        $sections = [];
        $worst = Status::INFO;

        foreach ($this->sections as $label => $rows) {
            $sectionStatus = Status::INFO;
            $serialized = [];
            foreach ($rows as $row) {
                $sectionStatus = $this->status->worst($sectionStatus, $row->getStatus());
                $serialized[] = $row->toArray();
            }
            $worst = $this->status->worst($worst, $sectionStatus);
            $sections[] = [
                'label' => $label,
                'status' => $sectionStatus,
                'rows' => $serialized,
            ];
        }

        return [
            'status' => $this->overrideStatus ?? $worst,
            'summary' => $this->summary,
            'sections' => $sections,
        ];
    }
}
