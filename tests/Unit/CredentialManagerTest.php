<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Client\CredentialManager;
use Ubxty\BedrockAi\Exceptions\ConfigurationException;

class CredentialManagerTest extends TestCase
{
    protected function getTestKeys(): array
    {
        return [
            [
                'label' => 'Primary',
                'aws_key' => 'AKIAIOSFODNN7EXAMPLE',
                'aws_secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'region' => 'us-east-1',
            ],
            [
                'label' => 'Secondary',
                'aws_key' => 'AKIAI44QH8DHBEXAMPLE',
                'aws_secret' => 'je7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY',
                'region' => 'eu-west-1',
            ],
            [
                'label' => 'Tertiary',
                'aws_key' => 'AKIAI77QH8DHBEXAMPLE',
                'aws_secret' => 'ab7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY',
                'region' => 'us-west-2',
            ],
        ];
    }

    public function testThrowsExceptionOnEmptyKeys(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No AWS credential keys configured.');

        new CredentialManager([]);
    }

    public function testCurrentReturnsFirstKey(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $current = $manager->current();

        $this->assertSame('Primary', $current['label']);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $current['aws_key']);
        $this->assertSame('us-east-1', $current['region']);
    }

    public function testNextAdvancesToNextKey(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $this->assertTrue($manager->next());
        $this->assertSame('Secondary', $manager->current()['label']);

        $this->assertTrue($manager->next());
        $this->assertSame('Tertiary', $manager->current()['label']);

        $this->assertFalse($manager->next());
    }

    public function testNextReturnsFalseWhenNoMoreKeys(): void
    {
        $manager = new CredentialManager([['label' => 'Only', 'aws_key' => 'k', 'aws_secret' => 's', 'region' => 'us-east-1']]);

        $this->assertFalse($manager->next());
    }

    public function testResetGoesBackToFirstKey(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $manager->next();
        $manager->next();
        $this->assertSame('Tertiary', $manager->current()['label']);

        $manager->reset();
        $this->assertSame('Primary', $manager->current()['label']);
        $this->assertSame(0, $manager->currentIndex());
    }

    public function testSelectSpecificKey(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $manager->select(2);
        $this->assertSame('Tertiary', $manager->current()['label']);

        $manager->select(0);
        $this->assertSame('Primary', $manager->current()['label']);
    }

    public function testSelectThrowsExceptionForInvalidIndex(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Key index 10 does not exist.');

        $manager->select(10);
    }

    public function testCountReturnsNumberOfKeys(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $this->assertSame(3, $manager->count());
    }

    public function testCurrentIndex(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $this->assertSame(0, $manager->currentIndex());

        $manager->next();
        $this->assertSame(1, $manager->currentIndex());
    }

    public function testIsHttpModeDetectsAbskKeyInAwsKey(): void
    {
        $manager = new CredentialManager([
            ['label' => 'HTTP', 'aws_key' => 'ABSK-token-12345', 'aws_secret' => 'regular', 'region' => 'us-east-1'],
        ]);

        $this->assertTrue($manager->isHttpMode());
    }

    public function testIsHttpModeDetectsAbskKeyInAwsSecret(): void
    {
        $manager = new CredentialManager([
            ['label' => 'HTTP', 'aws_key' => 'regular', 'aws_secret' => 'ABSK-token-67890', 'region' => 'us-east-1'],
        ]);

        $this->assertTrue($manager->isHttpMode());
    }

    public function testIsHttpModeReturnsFalseForSdkKeys(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $this->assertFalse($manager->isHttpMode());
    }

    public function testGetHttpBearerTokenFromAwsKey(): void
    {
        $manager = new CredentialManager([
            ['label' => 'HTTP', 'aws_key' => 'ABSK-my-token', 'aws_secret' => 'regular', 'region' => 'us-east-1'],
        ]);

        $this->assertSame('ABSK-my-token', $manager->getHttpBearerToken());
    }

    public function testGetHttpBearerTokenFromAwsSecret(): void
    {
        $manager = new CredentialManager([
            ['label' => 'HTTP', 'aws_key' => 'regular', 'aws_secret' => 'ABSK-secret-token', 'region' => 'us-east-1'],
        ]);

        $this->assertSame('ABSK-secret-token', $manager->getHttpBearerToken());
    }

    public function testListReturnsKeysWithoutSecrets(): void
    {
        $manager = new CredentialManager($this->getTestKeys());

        $list = $manager->list();

        $this->assertCount(3, $list);

        $this->assertSame(0, $list[0]['index']);
        $this->assertSame('Primary', $list[0]['label']);
        $this->assertSame('us-east-1', $list[0]['region']);
        $this->assertTrue($list[0]['configured']);

        $this->assertArrayNotHasKey('aws_key', $list[0]);
        $this->assertArrayNotHasKey('aws_secret', $list[0]);
    }

    public function testListReportsUnconfiguredKeys(): void
    {
        $manager = new CredentialManager([
            ['label' => 'Empty', 'aws_key' => '', 'aws_secret' => '', 'region' => 'us-east-1'],
        ]);

        $list = $manager->list();
        $this->assertFalse($list[0]['configured']);
    }

    public function testKeyIndexIsReindexed(): void
    {
        // Non-sequential array keys should be reindexed
        $keys = [];
        $keys[5] = ['label' => 'A', 'aws_key' => 'k1', 'aws_secret' => 's1', 'region' => 'us-east-1'];
        $keys[10] = ['label' => 'B', 'aws_key' => 'k2', 'aws_secret' => 's2', 'region' => 'us-east-1'];

        $manager = new CredentialManager($keys);

        $this->assertSame(2, $manager->count());
        $this->assertSame('A', $manager->current()['label']);
    }
}
