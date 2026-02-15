<?php

namespace Ubxty\BedrockAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ubxty\BedrockAi\Client\ModelAliasResolver;

class ModelAliasResolverTest extends TestCase
{
    public function testResolvesRegisteredAlias(): void
    {
        $resolver = new ModelAliasResolver([
            'claude' => 'anthropic.claude-sonnet-4-v1:0',
            'haiku' => 'anthropic.claude-3-5-haiku-v1:0',
        ]);

        $this->assertSame('anthropic.claude-sonnet-4-v1:0', $resolver->resolve('claude'));
        $this->assertSame('anthropic.claude-3-5-haiku-v1:0', $resolver->resolve('haiku'));
    }

    public function testReturnsOriginalStringForUnknownAlias(): void
    {
        $resolver = new ModelAliasResolver(['claude' => 'anthropic.claude-sonnet-4-v1:0']);

        $this->assertSame('anthropic.claude-v2', $resolver->resolve('anthropic.claude-v2'));
    }

    public function testEmptyAliasesReturnOriginal(): void
    {
        $resolver = new ModelAliasResolver();

        $this->assertSame('some-model-id', $resolver->resolve('some-model-id'));
    }

    public function testRegisterAddsNewAlias(): void
    {
        $resolver = new ModelAliasResolver();

        $resolver->register('fast', 'anthropic.claude-3-5-haiku-v1:0');

        $this->assertSame('anthropic.claude-3-5-haiku-v1:0', $resolver->resolve('fast'));
    }

    public function testRegisterOverwritesExistingAlias(): void
    {
        $resolver = new ModelAliasResolver(['claude' => 'old-model']);

        $resolver->register('claude', 'new-model');

        $this->assertSame('new-model', $resolver->resolve('claude'));
    }

    public function testIsAliasReturnsTrueForRegisteredAlias(): void
    {
        $resolver = new ModelAliasResolver(['claude' => 'anthropic.claude-v1']);

        $this->assertTrue($resolver->isAlias('claude'));
    }

    public function testIsAliasReturnsFalseForNonAlias(): void
    {
        $resolver = new ModelAliasResolver(['claude' => 'anthropic.claude-v1']);

        $this->assertFalse($resolver->isAlias('unknown'));
    }

    public function testAllReturnsAllAliases(): void
    {
        $aliases = [
            'claude' => 'anthropic.claude-sonnet-4-v1:0',
            'haiku' => 'anthropic.claude-3-5-haiku-v1:0',
        ];

        $resolver = new ModelAliasResolver($aliases);

        $this->assertSame($aliases, $resolver->all());
    }

    public function testAllReturnsEmptyArrayWhenNoAliases(): void
    {
        $resolver = new ModelAliasResolver();

        $this->assertSame([], $resolver->all());
    }
}
