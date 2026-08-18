<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Dummy;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DummyTest extends UnitTestCase
{
    #[Test]
    public function getExtensionKeyReturnsExtensionKey(): void
    {
        $this->assertSame('seeder', (new Dummy())->getExtensionKey());
    }
}
