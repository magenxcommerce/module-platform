<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Magenx\Platform\Model\Security;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Which release this store runs, named the way the advisory API names it.
 *
 * Magento reports the release of its product metapackage — 2.4.8-p5 — and the
 * edition. Module versions (104.0.8-p5) are a different numbering and only
 * imply the release; this is the authoritative answer.
 *
 * Mage-OS ships the same ProductMetadata with a distribution name and version
 * of its own beside Magento's. Those methods are not on the interface, so they
 * are probed for rather than assumed.
 */
class InstalledRelease
{
    private ProductMetadataInterface $productMetadata;

    /**
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(ProductMetadataInterface $productMetadata)
    {
        $this->productMetadata = $productMetadata;
    }

    /**
     * @return array{version: string, edition: string, distribution: string, magento_version: string}
     * @throws LocalizedException When Magento cannot name its own release.
     */
    public function get(): array
    {
        $magentoVersion = (string) $this->productMetadata->getVersion();
        if ($magentoVersion === '' || stripos($magentoVersion, 'unknown') !== false) {
            throw new LocalizedException(__(
                'Magento could not name its own release. It reads it from the composer metadata of '
                . 'magento/product-*-edition, which this installation does not expose.'
            ));
        }

        $edition = (string) $this->productMetadata->getEdition();
        $distribution = $this->distributionFor($edition);
        $version = $magentoVersion;

        if (method_exists($this->productMetadata, 'getDistributionName')
            && stripos((string) $this->productMetadata->getDistributionName(), 'mage-os') !== false
        ) {
            $distribution = 'mage-os';
            if (method_exists($this->productMetadata, 'getDistributionVersion')) {
                $version = (string) $this->productMetadata->getDistributionVersion() ?: $magentoVersion;
            }
        }

        return [
            'version' => $version,
            'edition' => $edition,
            'distribution' => $distribution,
            'magento_version' => $magentoVersion,
        ];
    }

    /**
     * Community is Magento Open Source; Enterprise and B2B are both Adobe
     * Commerce, which the API indexes as one distribution.
     *
     * @param string $edition
     * @return string
     */
    private function distributionFor(string $edition): string
    {
        return strcasecmp($edition, 'Community') === 0 ? 'magento-community' : 'magento-commerce';
    }
}
