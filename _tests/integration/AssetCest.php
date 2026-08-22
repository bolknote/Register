<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Asset\AssetMerge;
use Register\Core\Asset\AssetMergeFactory;
use Register\Core\HttpClient\HttpClient;

class AssetCest
{
    public function testAssetMergeUsesSslVerifyingHttpClient(\IntegrationTester $I): void
    {
        /** @var HttpClient $assetHttpClient */
        $assetHttpClient = $I->grabService('asset_http_client');
        $I->assertTrue($this->isHttpClientSslVerificationEnabled($assetHttpClient));

        /** @var AssetMergeFactory $factory */
        $factory = $I->grabService(AssetMergeFactory::class);
        $merge   = $factory->create('test_scripts', AssetMerge::TYPE_JS);

        $httpClientProperty = new \ReflectionProperty(AssetMerge::class, 'httpClient');

        $I->assertSame($assetHttpClient, $httpClientProperty->getValue($merge));
    }

    private function isHttpClientSslVerificationEnabled(HttpClient $httpClient): bool
    {
        $verifySslProperty = new \ReflectionProperty(HttpClient::class, 'verifySsl');

        return $verifySslProperty->getValue($httpClient);
    }
}
